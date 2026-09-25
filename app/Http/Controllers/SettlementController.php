<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\Settlement;
use App\Models\SharedExpense;
use App\Models\Task;
use App\Services\BalanceSheet;
use App\Services\CurrentWorkspace;
use App\Services\FollowUpScheduler;
use App\Services\SplitCalculator;
use App\Support\JalaliDate;
use App\Support\PersianText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared expenses and settling up.
 *
 * The screen answers one question and then removes the reason anybody had to
 * ask it: who owes whom, and the shortest set of transfers that ends it.
 */
class SettlementController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly BalanceSheet $balances,
    ) {}

    public function index()
    {
        $workspace = $this->workspace->get();

        return view('settlements.index', [
            'workspace' => $workspace,
            'members' => $workspace->members()->orderBy('name')->get(),
            'balances' => $this->balances->balances($workspace),
            'transfers' => $this->balances->transfers($workspace),
            'expenses' => SharedExpense::forWorkspace($workspace->id)
                ->with(['payer', 'shares.user'])
                ->latest('spent_on')
                ->latest('id')
                ->limit(25)
                ->get(),
            'settlements' => Settlement::forWorkspace($workspace->id)
                ->with(['from', 'to'])
                ->latest('settled_on')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * Record something one person paid for several.
     *
     * The split is computed rather than typed whenever it can be: an even
     * split is what almost every group wants, and asking six people's shares
     * by hand is how a feature stops being used in week two.
     */
    public function storeExpense(Request $request, SplitCalculator $splitter)
    {
        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'spent_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'payer_id' => [
                'required',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'participants' => ['required', 'array', 'min:1'],
            'participants.*' => [
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ], [
            'participants.required' => 'دست‌کم یک نفر باید در این خرج سهیم باشد.',
        ]);

        $spentOn = $this->toGregorian($validated['spent_date']);

        if ($spentOn === null) {
            throw ValidationException::withMessages(['spent_date' => 'تاریخ شمسی معتبر نیست.']);
        }

        $shares = $splitter->equally(
            $validated['amount'],
            array_map('intval', $validated['participants']),
        );

        DB::transaction(function () use ($workspace, $request, $validated, $spentOn, $shares) {
            $expense = SharedExpense::create([
                'workspace_id' => $workspace->id,
                'created_by' => $request->user()->id,
                'payer_id' => $validated['payer_id'],
                'title' => $validated['title'],
                'amount' => $validated['amount'],
                'spent_on' => $spentOn,
                'note' => $validated['note'] ?? null,
            ]);

            foreach ($shares as $userId => $amount) {
                $expense->shares()->create(['user_id' => $userId, 'amount' => $amount]);
            }
        });

        return back()->with('status', 'خرج ثبت شد و بین همه تقسیم شد.');
    }

    /**
     * Record money handed over.
     *
     * Capped at what the balance sheet says is actually due between these
     * two, so a mistyped figure cannot conjure a credit that then has to be
     * argued about.
     */
    public function storeSettlement(Request $request)
    {
        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'from_user_id' => [
                'required',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'to_user_id' => [
                'required',
                'different:from_user_id',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'to_user_id.different' => 'پرداخت‌کننده و گیرنده نمی‌توانند یک نفر باشند.',
        ]);

        // Recording "A paid B" wipes B's claim on A, so it is B's word — or
        // A's, which B can see and dispute — never a third friend's.
        $userId = $request->user()->id;

        if (! in_array($userId, [(int) $validated['from_user_id'], (int) $validated['to_user_id']], true)
            && $this->workspace->role() !== WorkspaceRole::Owner) {
            throw ValidationException::withMessages([
                'from_user_id' => 'فقط پرداخت‌کننده، گیرنده یا مدیر گروه می‌تواند این پرداخت را ثبت کند.',
            ]);
        }

        $owed = $this->balances->owedBetween(
            $workspace,
            (int) $validated['from_user_id'],
            (int) $validated['to_user_id'],
        );

        if ($owed === 0) {
            throw ValidationException::withMessages([
                'amount' => 'بین این دو نفر بدهی‌ای ثبت نشده.',
            ]);
        }

        if ($validated['amount'] > $owed) {
            throw ValidationException::withMessages([
                'amount' => 'بیشتر از بدهی است. حداکثر '.number_format($owed).' ریال.',
            ]);
        }

        $settlement = Settlement::create([
            'workspace_id' => $workspace->id,
            'recorded_by' => $request->user()->id,
            'from_user_id' => $validated['from_user_id'],
            'to_user_id' => $validated['to_user_id'],
            'amount' => $validated['amount'],
            'settled_on' => now()->toDateString(),
            'note' => $validated['note'] ?? null,
        ]);

        Activity::record($settlement, 'settlement.recorded', $workspace->id, $request->user()->id);

        return back()->with('status', 'پرداخت ثبت شد.');
    }

    /**
     * Turn a debt into a task, which puts it on the ladder.
     *
     * Deliberately a button somebody presses rather than a nightly sweep.
     * The engine chasing an unpaid invoice is a company doing its job; the
     * same engine texting a friend about dinner money, unprompted, is a
     * product nobody keeps installed. Once it is started the follow-up is
     * automatic — the decision to start it is not.
     */
    public function remind(Request $request, FollowUpScheduler $scheduler)
    {
        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'from_user_id' => [
                'required',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'to_user_id' => [
                'required',
                'different:from_user_id',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $amount = $this->balances->owedBetween(
            $workspace,
            (int) $validated['from_user_id'],
            (int) $validated['to_user_id'],
        );

        if ($amount === 0) {
            return back()->withErrors(['remind' => 'بدهی‌ای برای یادآوری نیست.']);
        }

        $creditor = $workspace->members()->where('users.id', $validated['to_user_id'])->first();

        $existing = Task::forWorkspace($workspace->id)
            ->chaseable()
            ->where('assignee_id', $validated['from_user_id'])
            ->where('title', 'like', 'تسویه حساب با %')
            ->exists();

        if ($existing) {
            return back()->withErrors(['remind' => 'یادآوری قبلی هنوز باز است.']);
        }

        $task = Task::create([
            'workspace_id' => $workspace->id,
            'title' => 'تسویه حساب با '.PersianText::truncate($creditor?->firstName() ?? 'دوستتان', 20),
            'description' => sprintf('مبلغ %s ریال', number_format($amount)),
            'assignee_id' => $validated['from_user_id'],
            'creator_id' => $request->user()->id,
            'due_at' => now()->addDays(3)->setTime(17, 0),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Open,
        ]);

        $scheduler->scheduleFor($task);

        return back()->with('status', 'یادآوری ساخته شد. از این به بعد سامانه پیگیری‌اش می‌کند.');
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
