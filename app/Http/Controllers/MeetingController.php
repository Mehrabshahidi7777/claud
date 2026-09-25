<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Meeting;
use App\Models\Task;
use App\Services\AiQuota;
use App\Services\CurrentWorkspace;
use App\Services\FollowUpScheduler;
use App\Services\MeetingParser;
use App\Support\JalaliDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeetingController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly FollowUpScheduler $scheduler,
    ) {}

    public function index()
    {
        $workspace = $this->workspace->get();

        return view('meetings.index', [
            'workspace' => $workspace,
            'canManage' => $this->workspace->can(Permission::ManageMeetings),
            'meetings' => Meeting::where('workspace_id', $workspace->id)
                ->withCount('tasks')
                ->latest('held_at')
                ->paginate(20),
        ]);
    }

    public function create()
    {
        abort_unless($this->workspace->can(Permission::ManageMeetings), 403);

        return view('meetings.create', ['workspace' => $this->workspace->get()]);
    }

    /**
     * The notes are saved first, then read. Keeping them whether or not the
     * model is reachable is the point: the record is worth having on its own,
     * and it is the only way to re-run the extraction later.
     */
    public function store(Request $request, MeetingParser $parser, AiQuota $quota)
    {
        abort_unless($this->workspace->can(Permission::ManageMeetings), 403);

        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'held_date' => ['nullable', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'notes' => ['required', 'string', 'min:20', 'max:20000'],
        ]);

        $heldAt = $this->resolveDate($validated['held_date'] ?? null) ?? now();

        $meeting = Meeting::create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'title' => $validated['title'],
            'held_at' => $heldAt,
            'notes' => $validated['notes'],
        ]);

        // Over the day's allowance the meeting is still recorded, with its
        // full text, exactly as when the model is down.
        $parsed = $quota->take($workspace)
            ? $parser->parse($meeting, $workspace)
            : ['summary' => null, 'decisions' => [], 'actions' => [], 'used_ai' => false];

        $meeting->update([
            'summary' => $parsed['summary'],
            'decisions' => $parsed['decisions'] ?: null,
            'processed_by_ai' => $parsed['used_ai'],
        ]);

        Activity::record($meeting, 'meeting.recorded', $workspace->id, $request->user()->id);

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('draftActions', $parsed['actions']);
    }

    public function show(Request $request, Meeting $meeting)
    {
        abort_unless($meeting->workspace_id === $this->workspace->get()->id, 404);

        return view('meetings.show', [
            'workspace' => $meeting->workspace,
            'canManage' => $this->workspace->can(Permission::ManageMeetings),
            'meeting' => $meeting->load('tasks.assignee', 'creator'),

            // Drafts survive exactly one redirect. They are a proposal, not a
            // record — nothing is stored until the manager confirms an item.
            'draftActions' => session('draftActions', []),
            'members' => $meeting->workspace->members()->orderBy('name')->get(),
        ]);
    }

    /**
     * Confirm one action item. Goes through the same creation path as a task
     * typed by hand, so it gets the same validation and the same ladder.
     */
    public function confirmAction(Request $request, Meeting $meeting)
    {
        abort_unless($meeting->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($this->workspace->can(Permission::ManageMeetings), 403);

        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assignee_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'due_date' => ['nullable', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
        ]);

        $dueAt = $this->resolveDate($validated['due_date'] ?? null);

        if (filled($validated['due_date'] ?? null) && $dueAt === null) {
            throw ValidationException::withMessages(['due_date' => 'تاریخ شمسی معتبر نیست.']);
        }

        $task = Task::create([
            'workspace_id' => $workspace->id,
            'meeting_id' => $meeting->id,
            'title' => $validated['title'],
            'assignee_id' => $validated['assignee_id'] ?? null,
            'creator_id' => $request->user()->id,
            'due_at' => $dueAt,
            'priority' => $validated['priority'],
            'status' => TaskStatus::Open,
        ]);

        $this->scheduler->scheduleFor($task);

        Activity::record($task, 'task.created_from_meeting', $workspace->id, $request->user()->id);

        return back()->with('status', 'تسک ثبت شد و پیگیری‌اش زمان‌بندی شد.');
    }

    /**
     * Run the notes through the model again. The rules will be wrong in the
     * first months, and this is why the raw text is kept.
     */
    public function reparse(Meeting $meeting, MeetingParser $parser, AiQuota $quota)
    {
        abort_unless($meeting->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($this->workspace->can(Permission::ManageMeetings), 403);

        if (! $quota->take($this->workspace->get())) {
            return back()->withErrors(['ai' => 'سقف استفاده‌ی امروز از دستیار هوشمند پر شده است.']);
        }

        $parsed = $parser->parse($meeting, $this->workspace->get());

        if (! $parsed['used_ai']) {
            return back()->withErrors(['ai' => 'دستیار هوشمند در دسترس نیست.']);
        }

        $meeting->update([
            'summary' => $parsed['summary'],
            'decisions' => $parsed['decisions'] ?: null,
            'processed_by_ai' => true,
        ]);

        return back()->with('draftActions', $parsed['actions']);
    }

    private function resolveDate(?string $jalaliDate): ?Carbon
    {
        if ($jalaliDate === null) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', preg_split('/[\/\-]/', $jalaliDate));

        $date = JalaliDate::toGregorian($year, $month, $day);

        if ($date === null) {
            return null;
        }

        return Carbon::parse($date->toDateString(), $this->workspace->get()->timezone())
            ->setTime((int) explode(':', config('followup.working_hours.end', '17:00'))[0], 0)
            ->utc();
    }
}
