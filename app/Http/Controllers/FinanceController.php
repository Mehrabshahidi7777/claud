<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Enums\Permission;
use App\Enums\ReceivableStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Expense;
use App\Models\Receivable;
use App\Services\AiQuota;
use App\Services\CurrentWorkspace;
use App\Services\ExpenseParser;
use App\Services\FinanceReport;
use App\Support\JalaliDate;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The money pages: what went out, what is owed, and what was approved and
 * never accounted for.
 *
 * Deliberately not a ledger. There are no journal entries here and no chart
 * of accounts — the company's accounting package owns those. What this owns
 * is the half that needs a follow-up engine behind it.
 */
class FinanceController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly FinanceReport $report,
    ) {}

    public function index(Request $request)
    {
        $workspace = $this->guard();

        [$from, $to] = $this->window($request);

        return view('finance.index', [
            'workspace' => $workspace,
            'spending' => $this->report->spending($workspace, $from, $to),
            'receivables' => $this->report->receivables($workspace),
            'unrecorded' => $this->report->approvedButUnrecorded($workspace),
            'monthOffset' => (int) $request->integer('month'),
        ]);
    }

    public function expenses(Request $request)
    {
        $workspace = $this->guard();

        return view('finance.expenses', [
            'workspace' => $workspace,
            'categories' => ExpenseCategory::cases(),
            'expenses' => Expense::forWorkspace($workspace->id)
                ->with(['creator', 'approvalRequest'])
                ->latest('spent_on')
                ->latest('id')
                ->paginate(30),

            // Approved purchases with nothing filed against them yet, offered
            // as the starting point for a new expense. This is the path that
            // keeps the two halves connected without anyone having to link
            // them by hand.
            'openApprovals' => $this->report->approvedButUnrecorded($workspace),
            'draft' => session('expenseDraft'),
        ]);
    }

    public function storeExpense(Request $request)
    {
        $workspace = $this->guard();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'spent_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'approval_request_id' => [
                'nullable',
                Rule::exists('approval_requests', 'id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $spentOn = $this->toGregorian($validated['spent_date']);

        if ($spentOn === null) {
            throw ValidationException::withMessages(['spent_date' => 'تاریخ شمسی معتبر نیست.']);
        }

        $expense = Expense::create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'approval_request_id' => $validated['approval_request_id'] ?? null,
            'category' => $validated['category'],
            'title' => $validated['title'],
            'vendor' => $validated['vendor'] ?? null,
            'amount' => $validated['amount'],
            'spent_on' => $spentOn,
            'note' => $validated['note'] ?? null,
        ]);

        Activity::record($expense, 'expense.recorded', $workspace->id, $request->user()->id);

        return back()->with('status', 'هزینه ثبت شد.');
    }

    /**
     * The free-text shortcut, same shape as the one on the tasks page: a
     * sentence goes in, a filled form comes back, and the manager confirms.
     * Nothing is stored by the model.
     */
    public function parseExpense(Request $request, ExpenseParser $parser, AiQuota $quota)
    {
        $workspace = $this->guard();

        $validated = $request->validate([
            'text' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        if (! $quota->take($workspace)) {
            return back()->withErrors([
                'ai' => 'سقف استفاده‌ی امروز از دستیار هوشمند پر شده است. هزینه را دستی ثبت کنید.',
            ])->withInput();
        }

        $result = $parser->parse($validated['text']);

        if (! $result['ok']) {
            return back()->withErrors([
                'ai' => $result['reason'] === 'unavailable'
                    ? 'دستیار هوشمند در دسترس نیست. هزینه را دستی ثبت کنید.'
                    : 'از این متن هزینه‌ای در نیامد. دستی ثبت کنید.',
            ])->withInput();
        }

        return back()->with('expenseDraft', $result['expense']);
    }

    public function receivables(Request $request)
    {
        $workspace = $this->guard();

        return view('finance.receivables', [
            'workspace' => $workspace,
            'members' => $workspace->members()->orderBy('name')->get(),
            'receivables' => Receivable::forWorkspace($workspace->id)
                ->with(['owner', 'task'])
                ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [
                    ReceivableStatus::Open->value,
                    ReceivableStatus::Partial->value,
                ])
                ->orderBy('due_on')
                ->paginate(30),
        ]);
    }

    public function storeReceivable(Request $request)
    {
        $workspace = $this->guard();

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'issued_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'due_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'owner_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $issuedOn = $this->toGregorian($validated['issued_date']);
        $dueOn = $this->toGregorian($validated['due_date']);

        if ($issuedOn === null || $dueOn === null) {
            throw ValidationException::withMessages(['due_date' => 'تاریخ شمسی معتبر نیست.']);
        }

        if ($dueOn < $issuedOn) {
            throw ValidationException::withMessages([
                'due_date' => 'سررسید نمی‌تواند قبل از تاریخ صدور باشد.',
            ]);
        }

        $receivable = Receivable::create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'owner_id' => $validated['owner_id'] ?? null,
            'customer_name' => $validated['customer_name'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'title' => $validated['title'],
            'amount' => $validated['amount'],
            'issued_on' => $issuedOn,
            'due_on' => $dueOn,
            'status' => ReceivableStatus::Open,
        ]);

        Activity::record($receivable, 'receivable.recorded', $workspace->id, $request->user()->id);

        return back()->with('status', 'مطالبه ثبت شد. اگر سررسیدش بگذرد، خودش تبدیل به تسک می‌شود.');
    }

    /**
     * Record money received. A partial payment stays outstanding and keeps
     * being chased for what is left — settling on the first instalment is how
     * the remainder quietly disappears.
     */
    public function settleReceivable(Request $request, Receivable $receivable)
    {
        $workspace = $this->guard();

        abort_unless($receivable->workspace_id === $workspace->id, 404);

        $validated = $request->validate([
            'received' => ['required', 'integer', 'min:1', 'max:'.$receivable->outstanding()],
        ]);

        $settled = $receivable->settled_amount + $validated['received'];
        $isFull = $settled >= $receivable->amount;

        $receivable->update([
            'settled_amount' => $settled,
            'status' => $isFull ? ReceivableStatus::Settled : ReceivableStatus::Partial,
        ]);

        // Paid in full ends the chase; a partial payment does not, so its task
        // is deliberately left alone to keep running its ladder.
        if ($isFull && $receivable->task !== null) {
            $receivable->task->update([
                'status' => TaskStatus::Done,
                'completed_at' => now(),
            ]);

            $receivable->task->followUps()->where('status', 'pending')->delete();
        }

        Activity::record($receivable, 'receivable.payment_recorded', $workspace->id, $request->user()->id, [
            'received' => $validated['received'],
        ]);

        return back()->with('status', $isFull ? 'تسویه شد.' : 'دریافت جزئی ثبت شد.');
    }

    private function guard()
    {
        abort_unless($this->workspace->can(Permission::ViewFinance), 403);

        return $this->workspace->get();
    }

    /**
     * The month being looked at, as a Gregorian range. Offset zero is the
     * last thirty days rather than a calendar month, because a Jalali month
     * boundary and a Gregorian one never line up and the figure a manager
     * wants is "recently", not "since the first".
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Request $request): array
    {
        $offset = max(0, min(24, (int) $request->integer('month')));

        $to = CarbonImmutable::now()->subDays(30 * $offset);

        return [$to->subDays(29)->startOfDay(), $to->endOfDay()];
    }

    private function toGregorian(string $jalaliDate): ?string
    {
        [$year, $month, $day] = array_map(
            'intval',
            preg_split('/[\/\-]/', PersianText::foldDigits($jalaliDate)),
        );

        return JalaliDate::toGregorian($year, $month, $day)?->toDateString();
    }
}
