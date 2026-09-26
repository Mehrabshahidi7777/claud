<?php

namespace App\Http\Controllers;

use App\Contracts\SmsDriver;
use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\Department;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CurrentWorkspace;
use App\Services\SeatLimit;
use App\Sms\PatternMessage;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adding a member takes a name and a number and nothing else. They become
 * assignable immediately — before they have signed in, and whether or not they
 * ever do. That is the whole differentiator: the field worker never has to
 * open the application.
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly SeatLimit $seats,
    ) {}

    public function index()
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->can(Permission::ManageMembers), 403);

        return view('members.index', [
            'workspace' => $workspace,
            'seatsUsed' => $this->seats->used($workspace),
            'seatLimit' => $this->seats->limit($workspace),
            'canBuySeats' => $this->seats->canBuyMore($workspace) && $this->workspace->can(Permission::ManageBilling),
            'seatPrice' => $this->seats->canBuyMore($workspace) ? app(BillingService::class)->seatQuote($workspace, 1) : null,
            'members' => $workspace->members()->orderBy('name')->get(),
            'roles' => array_values(array_filter(
                WorkspaceRole::assignable(),
                fn (WorkspaceRole $role) => $this->workspace->role()->covers($role),
            )),
            'departments' => $workspace->has('departments')
                ? Department::forWorkspace($workspace->id)->active()->orderBy('name')->get()
                : collect(),
        ]);
    }

    public function store(Request $request, SmsDriver $sms)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->can(Permission::ManageMembers), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            'department_id' => [
                'nullable',
                Rule::exists('departments', 'id')->where('workspace_id', $workspace->id),
            ],
            'manager_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $this->refuseOwnerRole($validated['role']);
        $this->refuseRoleAboveOwn(WorkspaceRole::from($validated['role']));

        $phone = PhoneNumber::normalize($validated['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'شماره موبایل معتبر نیست.']);
        }

        // Someone may already exist from another workspace, so the account is
        // reused rather than duplicated — one phone number is one person.
        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['name' => $validated['name']],
        );

        if ($workspace->members()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages(['phone' => 'این شماره قبلاً در این فضای کاری هست.']);
        }

        // The company pays per person, so a paid subscription holds as many
        // people as it paid for; the next one waits for more places.
        if (! $this->seats->canAdd($workspace)) {
            throw ValidationException::withMessages(['phone' => $this->seats->refusal($workspace)]);
        }

        $workspace->members()->attach($user, [
            'role' => $validated['role'],
            'department_id' => $validated['department_id'] ?? null,
            'manager_id' => $validated['manager_id'] ?? null,
        ]);

        $sms->send(PatternMessage::make($phone, 'welcome', [
            'name' => $user->firstName(),
            'workspace' => $workspace->name,
        ]));

        Activity::record($user, 'member.added', $workspace->id, $request->user()->id);

        return back()->with('status', $user->firstName().' اضافه شد و از همین حالا می‌توانید کار به او بسپارید.');
    }

    public function update(Request $request, User $member)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->can(Permission::ManageMembers), 403);

        $membership = $workspace->members()->where('users.id', $member->id)->first()?->pivot;

        abort_unless($membership !== null, 404);

        $currentRole = WorkspaceRole::from($membership->role);

        // The owner's row is theirs alone to edit, and editing it never
        // touches the role: the form has no "owner" option, so saving a
        // department used to demote the owner to admin as a side effect.
        if ($currentRole === WorkspaceRole::Owner) {
            abort_unless($member->id === $request->user()->id, 403);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            'department_id' => [
                'nullable',
                Rule::exists('departments', 'id')->where('workspace_id', $workspace->id),
            ],
            'manager_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'away_until' => ['nullable', 'date'],
        ]);

        if ($currentRole === WorkspaceRole::Owner) {
            $validated['role'] = WorkspaceRole::Owner->value;
        } else {
            $newRole = WorkspaceRole::from($validated['role']);

            $this->refuseOwnerRole($validated['role']);

            if ($member->id === $request->user()->id && $newRole !== $currentRole) {
                throw ValidationException::withMessages(['role' => 'نقش خودتان را نمی‌توانید تغییر دهید.']);
            }

            $this->refuseRoleAboveOwn($currentRole);
            $this->refuseRoleAboveOwn($newRole);
        }

        // Nobody is their own manager: an escalation that loops back to the
        // person who missed the deadline reaches nobody.
        if ((int) ($validated['manager_id'] ?? 0) === $member->id) {
            throw ValidationException::withMessages(['manager_id' => 'کسی نمی‌تواند مدیر خودش باشد.']);
        }

        $workspace->members()->updateExistingPivot($member->id, [
            'role' => $validated['role'],
            'department_id' => $validated['department_id'] ?? null,
            'manager_id' => $validated['manager_id'] ?? null,
            'away_until' => $validated['away_until'] ?? null,
        ]);

        return back()->with('status', 'تغییرات ثبت شد.');
    }

    /**
     * Ownership is not a role to hand out from a dropdown.
     *
     * There is one owner, they are whoever created the workspace, and they
     * hold the one permission that spends money. Letting an admin promote
     * somebody — or themselves — to owner from this form would make the
     * billing guard decorative.
     */
    private function refuseOwnerRole(string $role): void
    {
        if ($role === WorkspaceRole::Owner->value) {
            throw ValidationException::withMessages([
                'role' => 'مالک از این صفحه تعیین نمی‌شود.',
            ]);
        }
    }

    private function refuseRoleAboveOwn(WorkspaceRole $role): void
    {
        if (! $this->workspace->role()->covers($role)) {
            throw ValidationException::withMessages([
                'role' => 'این نقش دسترسی‌هایی دارد که خودتان ندارید.',
            ]);
        }
    }

    /**
     * Opting someone back in is a deliberate act by a manager, and the person
     * has to be told, so it is recorded rather than done quietly.
     */
    public function resumeSms(Request $request, User $member)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->can(Permission::ManageMembers), 403);
        abort_unless($workspace->members()->where('users.id', $member->id)->exists(), 404);

        $member->update(['sms_opted_out_at' => null]);

        Activity::record($member, 'member.sms_resumed', $workspace->id, $request->user()->id);

        return back()->with('status', 'ارسال پیامک برای این عضو دوباره فعال شد.');
    }
}
