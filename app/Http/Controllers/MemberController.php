<?php

namespace App\Http\Controllers;

use App\Contracts\SmsDriver;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\User;
use App\Services\CurrentWorkspace;
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
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function index()
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->role()->canManageMembers(), 403);

        return view('members.index', [
            'workspace' => $workspace,
            'members' => $workspace->members()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, SmsDriver $sms)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->role()->canManageMembers(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            'manager_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

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

        $workspace->members()->attach($user, [
            'role' => $validated['role'],
            'manager_id' => $validated['manager_id'] ?? null,
        ]);

        $sms->send(PatternMessage::make($phone, 'welcome', [
            'name' => $user->firstName(),
            'workspace' => $workspace->name,
        ]));

        Activity::record($user, 'member.added', $workspace->id, $request->user()->id);

        return back()->with('status', $user->firstName().' اضافه شد و از همین حالا قابل اساین شدن است.');
    }

    public function update(Request $request, User $member)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->role()->canManageMembers(), 403);
        abort_unless($workspace->members()->where('users.id', $member->id)->exists(), 404);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            'manager_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'away_until' => ['nullable', 'date'],
        ]);

        // Nobody is their own manager: an escalation that loops back to the
        // person who missed the deadline reaches nobody.
        if ((int) ($validated['manager_id'] ?? 0) === $member->id) {
            throw ValidationException::withMessages(['manager_id' => 'کسی نمی‌تواند مدیر خودش باشد.']);
        }

        $workspace->members()->updateExistingPivot($member->id, [
            'role' => $validated['role'],
            'manager_id' => $validated['manager_id'] ?? null,
            'away_until' => $validated['away_until'] ?? null,
        ]);

        return back()->with('status', 'تغییرات ثبت شد.');
    }

    /**
     * Opting someone back in is a deliberate act by a manager, and the person
     * has to be told, so it is recorded rather than done quietly.
     */
    public function resumeSms(Request $request, User $member)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->role()->canManageMembers(), 403);
        abort_unless($workspace->members()->where('users.id', $member->id)->exists(), 404);

        $member->update(['sms_opted_out_at' => null]);

        Activity::record($member, 'member.sms_resumed', $workspace->id, $request->user()->id);

        return back()->with('status', 'ارسال پیامک برای این عضو دوباره فعال شد.');
    }
}
