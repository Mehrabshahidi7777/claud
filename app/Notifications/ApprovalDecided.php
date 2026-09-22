<?php

namespace App\Notifications;

use App\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The answer, going back to whoever asked. A rejection carries its note: a
 * "no" with no reason attached is the thing that sends people back to the
 * group chat, which is what this feature exists to get them out of.
 */
class ApprovalDecided extends Notification
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
        $message = sprintf(
            'درخواست «%s» %s.',
            $this->request->title,
            $this->request->status->label(),
        );

        if (filled($this->request->decision_note)) {
            $message .= ' توضیح: '.$this->request->decision_note;
        }

        return [
            'approval_request_id' => $this->request->id,
            'workspace_id' => $this->request->workspace_id,
            'status' => $this->request->status->value,
            'title' => $this->request->title,
            'message' => $message,
        ];
    }
}
