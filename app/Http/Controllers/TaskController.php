<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use App\Services\CurrentWorkspace;
use App\Services\FollowUpScheduler;
use App\Support\JalaliDate;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly FollowUpScheduler $scheduler,
    ) {}

    public function index(Request $request)
    {
        $workspace = $this->workspace->get();

        $filter = $request->string('filter')->toString() ?: 'open';
        $search = trim($request->string('q')->toString());

        $tasks = $this->listQuery($request, $filter, $search)
            ->with(['assignee', 'followUps'])
            ->paginate(25)
            ->withQueryString();

        return view('tasks.index', [
            'tasks' => $tasks,
            'filter' => $filter,
            'search' => $search,
            'workspace' => $workspace,
            'members' => $workspace->members()->orderBy('name')->get(),
        ]);
    }

    /**
     * The list the viewer is looking at, as a spreadsheet: same filter, same
     * search, same visibility. A UTF-8 byte-order mark goes first because
     * without it Excel opens Persian text as mojibake.
     */
    public function export(Request $request): StreamedResponse
    {
        $filter = $request->string('filter')->toString() ?: 'open';
        $search = trim($request->string('q')->toString());

        $tasks = $this->listQuery($request, $filter, $search)->with(['assignee', 'creator']);
        $filename = 'tasks-'.str_replace('/', '-', JalaliDate::format(CarbonImmutable::now())).'.csv';

        return response()->streamDownload(function () use ($tasks) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['عنوان', 'مسئول', 'سررسید', 'وضعیت', 'اولویت', 'ثبت‌کننده', 'تعداد تأخیر']);

            $tasks->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $task) {
                    fputcsv($out, array_map($this->spreadsheetSafe(...), [
                        $task->title,
                        $task->assignee?->name ?? '',
                        $task->due_at ? JalaliDate::format(CarbonImmutable::parse($task->due_at)) : '',
                        $task->status->label(),
                        $task->priority->label(),
                        $task->creator?->name ?? '',
                        $task->defer_count,
                    ]));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A title typed as "=HYPERLINK(...)" would run as a formula when the
     * export is opened in Excel. A leading apostrophe makes it plain text.
     */
    private function spreadsheetSafe(string|int $value): string|int
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /**
     * What the list shows for a filter and a search, limited to what this
     * person may see. Shared by the page and its export so the two can never
     * disagree about which tasks are in view.
     */
    private function listQuery(Request $request, string $filter, string $search): Builder
    {
        $workspace = $this->workspace->get();
        $userId = $request->user()->id;

        // Folded the way incoming replies are, so a search typed with an
        // Arabic keyboard (ي، ك) or Persian digits still finds the task.
        $term = $search === '' ? '' : PersianText::normalize($search);

        return Task::forWorkspace($workspace->id)
            ->unless($this->workspace->seesAllTasks(), fn ($q) => $q->involving($userId))
            ->when($filter === 'open', fn ($q) => $q->chaseable())
            ->when($filter === 'overdue', fn ($q) => $q->chaseable()->where('due_at', '<', now()))
            ->when($filter === 'mine', fn ($q) => $q->chaseable()->where('assignee_id', $userId))
            ->when($filter === 'done', fn ($q) => $q->where('status', TaskStatus::Done->value))
            ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                $q->where('title', 'like', '%'.$term.'%')
                    ->orWhereHas('assignee', fn ($q) => $q->where('name', 'like', '%'.$term.'%'));
            }))
            ->orderByRaw('due_at IS NULL, due_at ASC');
    }

    /**
     * One task, with everything that has happened to it.
     *
     * The list page shows what is true now; this shows how it got there —
     * where the task came from, every rung the engine fired or skipped and
     * why, and who moved what. It is the page that answers "چرا این افتاده
     * گردن من" and the one that makes a demo land.
     */
    public function show(Task $task)
    {
        $this->workspace->authorize($task);

        $workspace = $this->workspace->get();

        return view('tasks.show', [
            'workspace' => $workspace,
            'task' => $task->load(['assignee', 'creator', 'meeting', 'followUps.recipient']),
            'members' => $workspace->members()->orderBy('name')->get(),
            'canCancel' => $this->workspace->can(Permission::CancelTasks),
            'canSeeMeetings' => $this->workspace->can(Permission::ManageMeetings),

            'activities' => Activity::where('workspace_id', $workspace->id)
                ->where('subject_type', $task->getMorphClass())
                ->where('subject_id', $task->getKey())
                ->with('user')
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assignee_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            // Jalali, exactly as the form presents it. The conversion happens
            // here so nothing downstream ever sees a Jalali date.
            'due_date' => ['nullable', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'may_break_quiet_hours' => ['nullable', 'boolean'],
        ]);

        $dueAt = $this->resolveDueAt($validated['due_date'] ?? null, $validated['due_time'] ?? null);

        // The regex above accepts 1405/13/45; only the calendar knows it is not
        // a date. Letting it through would silently store a task with no
        // deadline — which means no follow-up at all, while the manager
        // believes they set one.
        if (filled($validated['due_date'] ?? null) && $dueAt === null) {
            throw ValidationException::withMessages([
                'due_date' => 'تاریخ شمسی معتبر نیست.',
            ]);
        }

        $task = Task::create([
            'workspace_id' => $workspace->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'assignee_id' => $validated['assignee_id'] ?? null,
            'creator_id' => $request->user()->id,
            'due_at' => $dueAt,
            'priority' => $validated['priority'],
            'status' => TaskStatus::Open,
            'may_break_quiet_hours' => (bool) ($validated['may_break_quiet_hours'] ?? false),
        ]);

        $this->scheduler->scheduleFor($task);

        Activity::record($task, 'task.created', $workspace->id, $request->user()->id);

        return redirect()->route('tasks.index')->with('status', 'تسک ثبت شد و پیگیری‌اش زمان‌بندی شد.');
    }

    public function complete(Request $request, Task $task)
    {
        $this->workspace->authorize($task);

        $task->update([
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);

        // Nothing left to chase: pending rungs would be pursuing finished work.
        $task->followUps()->where('status', 'pending')->delete();

        Activity::record($task, 'task.completed', $task->workspace_id, $request->user()->id);

        return back()->with('status', 'تسک بسته شد.');
    }

    public function cancel(Request $request, Task $task)
    {
        $this->workspace->authorize($task);

        abort_unless($this->workspace->can(Permission::CancelTasks), 403);

        $task->update(['status' => TaskStatus::Cancelled]);
        $task->followUps()->where('status', 'pending')->delete();

        Activity::record($task, 'task.cancelled', $task->workspace_id, $request->user()->id);

        return back()->with('status', 'تسک لغو شد.');
    }

    public function reschedule(Request $request, Task $task)
    {
        $this->workspace->authorize($task);

        $validated = $request->validate([
            'due_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'due_time' => ['nullable', 'date_format:H:i'],
        ]);

        $dueAt = $this->resolveDueAt($validated['due_date'], $validated['due_time'] ?? null);

        if ($dueAt === null) {
            return back()->withErrors(['due_date' => 'تاریخ معتبر نیست.']);
        }

        $task->update(['due_at' => $dueAt, 'status' => TaskStatus::Open]);

        // A deadline that moves rebuilds the unsent rungs against the new date.
        $this->scheduler->scheduleFor($task->fresh());

        Activity::record($task, 'task.rescheduled', $task->workspace_id, $request->user()->id);

        return back()->with('status', 'ددلاین جدید ثبت شد و پیگیری بازسازی شد.');
    }

    /**
     * Turns the Jalali date and time from the form into a UTC instant. A time
     * left blank means the end of the working day rather than midnight, which
     * is what someone writing only a date actually means.
     */
    private function resolveDueAt(?string $jalaliDate, ?string $time): ?Carbon
    {
        if ($jalaliDate === null) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', preg_split('/[\/\-]/', $jalaliDate));

        $date = JalaliDate::toGregorian($year, $month, $day);

        if ($date === null) {
            return null;
        }

        [$hour, $minute] = $time !== null
            ? array_map('intval', explode(':', $time))
            : [(int) explode(':', config('followup.working_hours.end', '17:00'))[0], 0];

        return Carbon::parse($date->toDateString(), $this->workspace->get()->timezone())
            ->setTime($hour, $minute)
            ->utc();
    }
}
