<?php

namespace App\Notifications;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner their referral turned into a paying customer. Said out loud
 * because a gift nobody notices is a referral programme nobody repeats.
 */
class ReferralRewarded extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Workspace $referrer,
        private readonly Workspace $payer,
        private readonly int $days,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->referrer->id,
            'referred_workspace_id' => $this->payer->id,
            'days' => $this->days,
            'message' => sprintf(
                '«%s» که با معرفی شما آمده بود، اشتراکش را خرید. %d روز به اشتراک شما اضافه شد.',
                $this->payer->name,
                $this->days,
            ),
        ];
    }
}
