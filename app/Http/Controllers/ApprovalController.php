<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalType;
use App\Models\ApprovalRequest;
use App\Services\ApprovalService;
use App\Services\CurrentWorkspace;
use App\Support\JalaliDate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * Two lists on one page: what is waiting on me, and what I asked for. A
     * separate "my requests" page would mean the person who submitted it never
     * finds out what happened.
     */
    public function index(Request $request)
    {
        $workspace = $this->workspace->get();
        $user = $request->user();
        $role = $this->workspace->role();

        $awaitingMe = ApprovalRequest::forWorkspace($workspace->id)
            ->pending()
            ->where('requester_id', '!=', $user->id)
            ->when(
                ! $role->canManageMembers(),
                fn ($query) => $query->where('approver_id', $user->id),
            )
            ->with('requester')
            ->oldest()
            ->get()
            ->filter(fn (ApprovalRequest $item) => $this->approvals->canDecide($item, $user, $role))
            ->values();

        return view('approvals.index', [
            'workspace' => $workspace,
            'awaitingMe' => $awaitingMe,
            'conflicts' => $awaitingMe->mapWithKeys(
                fn (ApprovalRequest $item) => [$item->id => $this->approvals->conflictingTasks($item)],
            ),
            'mine' => ApprovalRequest::forWorkspace($workspace->id)
                ->where('requester_id', $user->id)
                ->with('decider')
                ->latest()
                ->limit(30)
                ->get(),
        ]);
    }

    public function create()
    {
        return view('approvals.create', [
            'workspace' => $this->workspace->get(),
            'types' => ApprovalType::cases(),
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ApprovalType::class)],
            'title' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['nullable', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'ends_on' => ['nullable', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ]);

        $type = ApprovalType::from($validated['type']);

        [$startsOn, $endsOn] = $this->resolveRange($type, $validated);

        $this->approvals->submit($workspace, $request->user(), [
            'type' => $type,
            'title' => $validated['title'],
            'reason' => $validated['reason'] ?? null,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'amount' => $type->needsAmount() ? ($validated['amount'] ?? null) : null,
        ]);

        return redirect()
            ->route('approvals.index')
            ->with('status', 'درخواست ثبت شد و به تأییدکننده اطلاع داده شد.');
    }

    public function decide(Request $request, ApprovalRequest $approval)
    {
        abort_unless($approval->workspace_id === $this->workspace->get()->id, 404);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        abort_unless(
            $this->approvals->canDecide($approval, $request->user(), $this->workspace->role()),
            403,
        );

        $note = $validated['note'] ?? null;

        if ($validated['decision'] === 'approve') {
            $this->approvals->approve($approval, $request->user(), $note);

            return back()->with('status', 'تأیید شد. تا پایان مرخصی، سامانه سراغ این عضو نمی‌رود.');
        }

        $this->approvals->reject($approval, $request->user(), $note);

        return back()->with('status', 'درخواست رد شد و به درخواست‌دهنده اطلاع داده شد.');
    }

    public function cancel(Request $request, ApprovalRequest $approval)
    {
        abort_unless($approval->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($approval->isCancellableBy($request->user()), 403);

        $this->approvals->cancel($approval, $request->user());

        return back()->with('status', 'درخواست لغو شد.');
    }

    /**
     * Leave without a usable range is the failure this guards against: an
     * unparseable date would become null, the request would be approved, and
     * nobody's `away_until` would ever be written — so the engine would keep
     * chasing a person who is on approved leave.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveRange(ApprovalType $type, array $validated): array
    {
        if (! $type->needsDateRange()) {
            return [null, null];
        }

        $startsOn = $this->toGregorian($validated['starts_on'] ?? null);
        $endsOn = $this->toGregorian($validated['ends_on'] ?? null) ?? $startsOn;

        if ($startsOn === null || $endsOn === null) {
            throw ValidationException::withMessages([
                'starts_on' => 'برای مرخصی باید تاریخ شروع و پایان معتبر بدهید.',
            ]);
        }

        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages([
                'ends_on' => 'تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.',
            ]);
        }

        return [$startsOn, $endsOn];
    }

    private function toGregorian(?string $jalaliDate): ?string
    {
        if (blank($jalaliDate)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', preg_split('/[\/\-]/', $jalaliDate));

        return JalaliDate::toGregorian($year, $month, $day)?->toDateString();
    }
}
