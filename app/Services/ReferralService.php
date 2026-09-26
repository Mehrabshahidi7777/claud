<?php

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Notifications\ReferralRewarded;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * "Bring a company, both of you get days."
 *
 * The newcomer's days arrive at sign-up, because that is the moment the offer
 * has to be true. The referrer's arrive when the newcomer first pays: a trial
 * costs nothing to start, so rewarding sign-ups would hand out free months to
 * anyone with a second SIM card.
 */
class ReferralService
{
    /**
     * Letters and digits that cannot be misread when a code is read aloud or
     * typed from a screenshot: no 0/o, 1/l/i.
     */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly BillingService $billing) {}

    public function bonusDays(): int
    {
        return (int) config('payment.referral_bonus_days', 15);
    }

    /**
     * The workspace's code, made the first time anyone asks for it.
     */
    public function codeFor(Workspace $workspace): string
    {
        if ($workspace->referral_code !== null) {
            return $workspace->referral_code;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $workspace->forceFill(['referral_code' => $this->randomCode()])->save();

                return $workspace->referral_code;
            } catch (UniqueConstraintViolationException) {
                $workspace->referral_code = null;
            }
        }

        throw new \RuntimeException('Could not allocate a referral code.');
    }

    public function findByCode(?string $code): ?Workspace
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return Workspace::where('referral_code', Str::lower($code))->first();
    }

    /**
     * Record who brought a brand new workspace and give it the extra days.
     * Returns whether the code counted.
     */
    public function attach(Workspace $newcomer, ?string $code): bool
    {
        $referrer = $this->findByCode($code);

        if ($referrer === null || $referrer->is($newcomer) || $newcomer->referred_by_workspace_id !== null) {
            return false;
        }

        $newcomer->forceFill(['referred_by_workspace_id' => $referrer->id])->save();

        // Free, there are no days to give; the link still records who
        // brought whom, which is what the platform panel shows.
        if ($this->billing->enabled()) {
            $this->billing->grantDays($newcomer, $this->bonusDays());
        }

        return true;
    }

    /**
     * Pay the referrer, once, when the workspace they brought pays.
     *
     * Claimed with a conditional update rather than read-then-write: the
     * bank's callback can arrive twice at once, and only the request that
     * moves the timestamp off null gets to hand out the days.
     */
    public function rewardReferrerOf(Workspace $payer): void
    {
        if ($payer->referred_by_workspace_id === null) {
            return;
        }

        $claimed = Workspace::whereKey($payer->id)
            ->whereNotNull('referred_by_workspace_id')
            ->whereNull('referral_rewarded_at')
            ->update(['referral_rewarded_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $referrer = Workspace::find($payer->referred_by_workspace_id);

        if ($referrer === null) {
            return;
        }

        $this->billing->grantDays($referrer, $this->bonusDays());

        Notification::send(
            $referrer->members()->wherePivot('role', WorkspaceRole::Owner->value)->get(),
            new ReferralRewarded($referrer, $payer, $this->bonusDays()),
        );
    }

    private function randomCode(): string
    {
        $code = '';

        for ($i = 0; $i < 7; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
