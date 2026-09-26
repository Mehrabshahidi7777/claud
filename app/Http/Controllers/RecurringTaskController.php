<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\RecurrenceAnchor;
use App\Enums\RecurrenceUnit;
use App\Enums\TaskPriority;
use App\Models\Activity;
use App\Models\RecurringTask;
use App\Services\CurrentWorkspace;
use App\Support\JalaliDate;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Work that comes back round: maintenance contracts for a company, the car
 * service and the insurance renewal for a household.
 */
class RecurringTaskController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function index(Request $request)
    {
        $workspace = $this->workspace->get();

        $recurrences = RecurringTask::forWorkspace($workspace->id)
            ->with('assignee')
            ->orderByDesc('is_active')
            ->orderBy('next_due_on')
            ->get();

        $active = $recurrences->where('is_active', true);

        return view('recurring.index', [
            'workspace' => $workspace,
            'canManage' => $this->workspace->can(Permission::ManageRecurring),
            'recurrences' => $recurrences,
            'members' => $workspace->members()->orderBy('name')->get(),
            'units' => RecurrenceUnit::cases(),
            'anchors' => RecurrenceAnchor::cases(),
            'priorities' => TaskPriority::cases(),

            // The argument for the whole module, as one figure: contracts
            // already past their date, priced. Shown only when the workspace
            // actually prices them — on a family plan it would be noise.
            'valueAtRisk' => (int) $active->filter->isOverdue()->sum('estimated_value'),
            'overdueCount' => $active->filter->isOverdue()->count(),
            'dueSoon' => $active
                ->filter(fn (RecurringTask $r) => ! $r->isOverdue() && $r->isDueToRaise())
                ->count(),

            // What the recurring work has been worth over the last year, which
            // is the number that says whether the subscription paid for itself.
            'earnedThisYear' => (int) $active
                ->filter(fn (RecurringTask $r) => $r->last_done_on !== null
                    && $r->last_done_on->greaterThan(now()->subYear()))
                ->sum('estimated_value'),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->workspace->can(Permission::ManageRecurring), 403);

        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'interval_unit' => ['required', Rule::enum(RecurrenceUnit::class)],
            'interval_count' => ['required', 'integer', 'min:1', 'max:60'],
            'anchor' => ['required', Rule::enum(RecurrenceAnchor::class)],
            'lead_days' => ['required', 'integer', 'min:0', 'max:180'],
            'next_due_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'estimated_value' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'assignee_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $nextDue = $this->toGregorian($validated['next_due_date']);

        if ($nextDue === null) {
            throw ValidationException::withMessages(['next_due_date' => 'تاریخ شمسی معتبر نیست.']);
        }

        // A lead time longer than the cycle would raise the next occurrence
        // before the current one is finished, forever.
        $cycleDays = $this->approximateCycleDays(
            RecurrenceUnit::from($validated['interval_unit']),
            $validated['interval_count'],
        );

        if ($validated['lead_days'] >= $cycleDays) {
            throw ValidationException::withMessages([
                'lead_days' => 'پیش‌آگاهی نمی‌تواند از خودِ دوره طولانی‌تر باشد.',
            ]);
        }

        $recurrence = RecurringTask::create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'assignee_id' => $validated['assignee_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_phone' => $validated['customer_phone'] ?? null,
            'interval_unit' => $validated['interval_unit'],
            'interval_count' => $validated['interval_count'],
            'anchor' => $validated['anchor'],
            'lead_days' => $validated['lead_days'],
            'next_due_on' => $nextDue,
            'estimated_value' => $validated['estimated_value'] ?? null,
            'priority' => $validated['priority'],
            'is_active' => true,
        ]);

        Activity::record($recurrence, 'recurrence.created', $workspace->id, $request->user()->id);

        return back()->with('status', 'ثبت شد. از حالا خودش سر وقت تبدیل به کار می‌شود.');
    }

    /**
     * Pausing rather than deleting. A contract that ended is history worth
     * keeping — how many times it ran and what it was worth is exactly what
     * someone looks up a year later.
     */
    public function toggle(Request $request, RecurringTask $recurring)
    {
        abort_unless($recurring->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($this->workspace->can(Permission::ManageRecurring), 403);

        $recurring->update(['is_active' => ! $recurring->is_active]);

        return back()->with(
            'status',
            $recurring->is_active ? 'دوباره فعال شد.' : 'متوقف شد. کار تازه‌ای نمی‌سازد.',
        );
    }

    private function toGregorian(string $jalaliDate): ?string
    {
        [$year, $month, $day] = array_map(
            'intval',
            preg_split('/[\/\-]/', PersianText::foldDigits($jalaliDate)),
        );

        return JalaliDate::toGregorian($year, $month, $day)?->toDateString();
    }

    /**
     * Roughly how many days one cycle spans. Only used to reject a lead time
     * that swallows the cycle, so an approximation is enough.
     */
    private function approximateCycleDays(RecurrenceUnit $unit, int $count): int
    {
        $today = CarbonImmutable::now()->startOfDay();

        return (int) $today->diffInDays($unit->advance($today, $count));
    }
}
