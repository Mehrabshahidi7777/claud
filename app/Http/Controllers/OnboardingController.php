<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\Workspace;
use App\Services\BillingService;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The last screen of sign-up: who you are, what to call this place, and which
 * of the three products it is.
 *
 * The type is asked here rather than inferred later because it cannot be
 * guessed and changes everything downstream — a family that is set up as a
 * company meets "صلاحیت پیمانکاری" and an escalation to their manager on day
 * two, and does not come back.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly ReferralService $referrals,
    ) {}

    public function show(Request $request)
    {
        if ($request->user()->name !== '' && $request->user()->workspaces()->exists()) {
            return redirect()->route('dashboard');
        }

        return view('auth.onboarding', [
            'types' => WorkspaceType::cases(),
            'referralBonusDays' => $this->referrals->findByCode($request->session()->get('referral_code')) !== null
                ? $this->referrals->bonusDays()
                : 0,
        ]);
    }

    public function store(Request $request)
    {
        // The same door the form is behind. Posting here again after
        // onboarding would mint a fresh workspace with a fresh free trial,
        // every trial period, for as long as anyone cared to.
        if ($request->user()->name !== '' && $request->user()->workspaces()->exists()) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'workspace' => ['required', 'string', 'max:100'],

            // The single most consequential answer on this page: it decides
            // which modules exist, whether an unanswered chase climbs to
            // somebody else, and what the people here are called on screen.
            'type' => ['required', Rule::enum(WorkspaceType::class)],
        ]);

        $user = $request->user();
        $referralCode = $request->session()->pull('referral_code');

        DB::transaction(function () use ($user, $validated, $referralCode) {
            $user->update(['name' => $validated['name']]);

            $workspace = Workspace::create([
                'name' => $validated['workspace'],
                'type' => $validated['type'],
            ]);

            // Whoever creates the workspace owns it, which also makes them the
            // default destination for an escalation that has nowhere else to go.
            $workspace->members()->attach($user, ['role' => WorkspaceRole::Owner->value]);

            // The trial starts here rather than at the first payment. Without
            // it a brand new customer meets the paywall before they have seen
            // the product work once.
            $this->billing->startTrial($workspace);

            // After the trial exists, so the referral days extend it.
            $this->referrals->attach($workspace, $referralCode);
        });

        // Somewhere they were heading before signing up, such as the invite
        // page from the login screen's button.
        return redirect()->intended(route('dashboard'));
    }
}
