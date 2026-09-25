<?php

namespace App\Http\Controllers;

use App\Enums\ContractKind;
use App\Enums\PartyType;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Contract;
use App\Services\ContractWatcher;
use App\Services\CurrentWorkspace;
use App\Support\JalaliDate;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contracts, licences and policies — anything with an expiry date that
 * somebody has to act on before it arrives.
 */
class ContractController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly ContractWatcher $watcher,
    ) {}

    public function index()
    {
        abort_unless($this->workspace->can(Permission::ViewContracts), 403);

        $workspace = $this->workspace->get();

        $contracts = Contract::forWorkspace($workspace->id)
            ->with(['owner', 'party'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('expires_on')
            ->get();

        $active = $contracts->where('status.value', 'active');

        $expired = $active->filter->hasExpired();

        return view('contracts.index', [
            'workspace' => $workspace,
            'canManage' => $this->workspace->can(Permission::ManageContracts),
            'contracts' => $contracts,
            'members' => $workspace->members()->orderBy('name')->get(),
            'kinds' => ContractKind::cases(),
            'partyTypes' => PartyType::cases(),

            'expired' => $expired,
            'expiringSoon' => $active->filter->isExpiringSoon(),

            // Said separately because it is a different sentence. A lapsed
            // stationery agreement is untidy; a lapsed contractor
            // qualification loses a tender the company has already paid to
            // bid for, and a lapsed staff contract is a liability.
            'seriousLapses' => $expired->filter(
                fn (Contract $contract) => $contract->kind->lapseIsSerious(),
            ),
        ]);
    }

    public function show(Contract $contract)
    {
        abort_unless($contract->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($this->workspace->can(Permission::ViewContracts), 403);

        return view('contracts.show', [
            'workspace' => $this->workspace->get(),
            'canManage' => $this->workspace->can(Permission::ManageContracts),
            'contract' => $contract->load(['owner', 'party', 'terms.recorder', 'tasks']),
            'activities' => Activity::where('workspace_id', $contract->workspace_id)
                ->where('subject_type', $contract->getMorphClass())
                ->where('subject_id', $contract->getKey())
                ->with('user')
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->workspace->can(Permission::ManageContracts), 403);

        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(ContractKind::class)],
            'party_type' => ['required', Rule::enum(PartyType::class)],
            'party_name' => ['required_without:party_user_id', 'nullable', 'string', 'max:255'],
            'party_user_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'starts_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'expires_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'value' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'notice_days' => ['required', 'integer', 'min:1', 'max:365'],
            'auto_renews' => ['nullable', 'boolean'],
            'owner_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

        [$startsOn, $expiresOn] = $this->resolveTerm($validated['starts_date'], $validated['expires_date']);

        // A staff contract named by picking a member should not also need the
        // name typing out; the pick is the better source either way.
        $partyName = $validated['party_name'] ?? null;

        if (filled($validated['party_user_id'] ?? null)) {
            $partyName = $workspace->members()
                ->where('users.id', $validated['party_user_id'])
                ->value('name') ?? $partyName;
        }

        $contract = Contract::create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'owner_id' => $validated['owner_id'] ?? null,
            'kind' => $validated['kind'],
            'party_type' => $validated['party_type'],
            'party_name' => $partyName,
            'party_user_id' => $validated['party_user_id'] ?? null,
            'title' => $validated['title'],
            'reference' => $validated['reference'] ?? null,
            'note' => $validated['note'] ?? null,
            'starts_on' => $startsOn,
            'expires_on' => $expiresOn,
            'value' => $validated['value'] ?? null,
            'notice_days' => $validated['notice_days'],
            'auto_renews' => (bool) ($validated['auto_renews'] ?? false),
        ]);

        Activity::record($contract, 'contract.recorded', $workspace->id, $request->user()->id);

        return redirect()
            ->route('contracts.show', $contract)
            ->with('status', 'ثبت شد. پیش از انقضا خودش یادآوری می‌کند.');
    }

    public function renew(Request $request, Contract $contract)
    {
        abort_unless($contract->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($this->workspace->can(Permission::ManageContracts), 403);
        abort_unless($contract->isActive(), 404);

        $validated = $request->validate([
            'starts_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'expires_date' => ['required', 'regex:/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/'],
            'value' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        [$startsOn, $expiresOn] = $this->resolveTerm($validated['starts_date'], $validated['expires_date']);

        $this->watcher->renew(
            $contract,
            $request->user(),
            CarbonImmutable::parse($startsOn),
            CarbonImmutable::parse($expiresOn),
            $validated['value'] ?? null,
            $validated['note'] ?? null,
        );

        return back()->with('status', 'تمدید ثبت شد. دوره‌ی قبلی در تاریخچه ماند.');
    }

    public function end(Request $request, Contract $contract)
    {
        abort_unless($contract->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($this->workspace->can(Permission::ManageContracts), 403);

        $this->watcher->end($contract, $request->user());

        return back()->with('status', 'خاتمه یافت. سابقه‌اش باقی می‌ماند.');
    }

    /**
     * Both dates, checked together.
     *
     * A term that ends before it starts is the silent failure this guards
     * against: it would sit permanently expired, raise a renewal every sweep,
     * and read as a bug in the engine rather than a typo in the form.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveTerm(string $startsDate, string $expiresDate): array
    {
        $startsOn = $this->toGregorian($startsDate);
        $expiresOn = $this->toGregorian($expiresDate);

        if ($startsOn === null || $expiresOn === null) {
            throw ValidationException::withMessages([
                'expires_date' => 'تاریخ شمسی معتبر نیست.',
            ]);
        }

        if ($expiresOn <= $startsOn) {
            throw ValidationException::withMessages([
                'expires_date' => 'تاریخ انقضا باید بعد از تاریخ شروع باشد.',
            ]);
        }

        return [$startsOn, $expiresOn];
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
