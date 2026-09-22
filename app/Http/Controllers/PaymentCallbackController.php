<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Where the bank sends the payer back.
 *
 * Deliberately outside the authenticated routes: the payer returns through a
 * redirect chain that may have dropped their session, and refusing them here
 * would strand a completed payment. Authorisation comes from the reference
 * instead, which only we and the bank know, and nothing is trusted until the
 * verify call agrees.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request, PaymentService $payments)
    {
        // Gateways differ on the verb they return with.
        $outcome = $payments->settle($request->all());

        $payment = $outcome['payment'];

        if ($outcome['settled']) {
            return view('billing.result', [
                'successful' => true,
                'payment' => $payment,
                'message' => 'پرداخت با موفقیت انجام و تأیید شد.',
            ]);
        }

        return view('billing.result', [
            'successful' => false,
            'payment' => $payment,
            'message' => $this->explain($outcome['reason']),
        ]);
    }

    private function explain(?string $reason): string
    {
        return match ($reason) {
            'gateway_declined' => 'پرداخت در درگاه انجام نشد یا لغو شد. مبلغی از حساب شما کسر نشده است.',

            // Said plainly rather than dressed up as success. If money did
            // move, the retry settles it and the customer is not left
            // wondering.
            'verification_pending' => 'پرداخت شما ثبت شد ولی تأیید نهایی هنوز انجام نشده است. اگر مبلغ کسر شده باشد، حداکثر تا چند دقیقه دیگر تأیید می‌شود.',

            'verification_failed' => 'تأیید پرداخت توسط بانک انجام نشد. اگر مبلغی کسر شده، ظرف ۷۲ ساعت به حساب شما برمی‌گردد.',
            'replayed_reference' => 'این تراکنش قبلاً ثبت شده است.',
            'unknown_reference', 'missing_reference' => 'اطلاعات این تراکنش شناسایی نشد.',
            default => 'پرداخت انجام نشد.',
        };
    }
}
