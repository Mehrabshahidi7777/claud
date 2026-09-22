<?php

namespace App\Notifications;

use App\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Someone is blocked until this is decided, so it lands in the approver's
 * in-app list as well as on their phone. Database only here — the SMS is sent
 * separately through the gate, which is the layer that knows about credit,
 * quiet hours and opt-outs.
 */
class ApprovalAwaiting extends Notification
{
    use Queueable;

    public function __construct(private readonly ApprovalRequest $request) {}

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
            'approval_request_id' => $this->request->id,
            'workspace_id' => $this->request->workspace_id,
            'type' => $this->request->type->value,
            'title' => $this->request->title,
            'message' => sprintf(
                'درخواست %s از %s: «%s» منتظر تصمیم شماست.',
                $this->request->type->label(),
                $this->request->requester?->firstName() ?? 'یکی از اعضا',
                $this->request->title,
            ),
        ];
    }
}
