<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Services\CurrentWorkspace;
use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referrals) {}

    /**
     * Where a shared link lands. The code rides along in the session through
     * the phone login and is spent when the newcomer creates their workspace.
     */
    public function capture(Request $request, string $code): RedirectResponse
    {
        if ($this->referrals->findByCode($code) !== null) {
            $request->session()->put('referral_code', $code);
        }

        return redirect()->route($request->user() ? 'dashboard' : 'login');
    }

    /**
     * The owner's link and what it has brought in. Behind the billing
     * permission: the reward is days on the subscription, which is the
     * owner's business.
     */
    public function index(CurrentWorkspace $current): View
    {
        abort_unless($current->can(Permission::ManageBilling), 403);

        $workspace = $current->get();

        $referred = $workspace->referrals()
            ->latest('id')
            ->get(['id', 'name', 'type', 'created_at', 'referral_rewarded_at']);

        return view('referrals.index', [
            'workspace' => $workspace,
            'link' => route('referral.capture', $this->referrals->codeFor($workspace)),
            'bonusDays' => $this->referrals->bonusDays(),
            'trialDays' => (int) config('payment.trial_days'),
            'referred' => $referred,
        ]);
    }
}
