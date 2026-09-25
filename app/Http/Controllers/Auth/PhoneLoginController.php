<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in is two screens and three fields: the number, the code, and — only
 * for someone genuinely new — their name and their company's. There is no
 * password to forget and no email to verify.
 */
class PhoneLoginController extends Controller
{
    public function show()
    {
        return view('auth.phone');
    }

    public function requestCode(Request $request, OtpService $otp)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $phone = PhoneNumber::normalize($validated['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'شماره موبایل معتبر نیست.',
            ]);
        }

        $result = $otp->request($phone, $request->ip());

        if (! $result['sent']) {
            throw ValidationException::withMessages([
                'phone' => $this->explain($result['reason'], $result['seconds_until_retry']),
            ]);
        }

        // The number rides the session rather than the URL so it cannot be
        // swapped between requesting a code and entering it.
        $request->session()->put('otp_phone', $phone);

        return redirect()->route('login.code');
    }

    public function showCodeForm(Request $request)
    {
        if (! $request->session()->has('otp_phone')) {
            return redirect()->route('login');
        }

        return view('auth.code', [
            'phone' => PhoneNumber::toLocal($request->session()->get('otp_phone')),
        ]);
    }

    public function verifyCode(Request $request, OtpService $otp)
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $phone = $request->session()->get('otp_phone');

        if ($phone === null) {
            return redirect()->route('login');
        }

        $result = $otp->verify($phone, $validated['code']);

        if (! $result['verified']) {
            throw ValidationException::withMessages([
                'code' => $this->explain($result['reason']),
            ]);
        }

        Auth::login($result['user'], remember: true);
        $request->session()->forget('otp_phone');
        $request->session()->regenerate();

        // Whoever runs the platform lands on its panel, unless they also use
        // پیگیر as a customer — then their own workspace, with a link across.
        if ($result['user']->isPlatformAdmin() && $result['user']->workspaces()->doesntExist()) {
            return redirect()->route('admin.dashboard');
        }

        // Someone with no name has never finished signing up, whether they are
        // new or were created by a manager who only entered a number.
        if ($result['user']->name === '' || $result['user']->workspaces()->doesntExist()) {
            return redirect()->route('onboarding');
        }

        // Signing in lands on the dashboard rather than the task list: it
        // is the one screen that answers "چه خبر؟" without a click, and it
        // adapts to whichever of the three products this workspace is.
        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function explain(?string $reason, ?int $seconds = null): string
    {
        return match ($reason) {
            'invalid_phone' => 'شماره موبایل معتبر نیست.',
            'too_soon' => 'کد تازه فرستاده شد. '.($seconds ?? 60).' ثانیه دیگر دوباره تلاش کنید.',
            'hourly_limit' => 'تعداد درخواست‌ها زیاد بود. یک ساعت دیگر تلاش کنید.',
            'daily_limit' => 'برای این شماره امروز کد زیادی درخواست شده. فردا دوباره تلاش کنید.',
            'ip_limit' => 'تعداد درخواست‌ها از این دستگاه زیاد بود. بعداً تلاش کنید.',
            'delivery_failed' => 'پیامک ارسال نشد. دوباره تلاش کنید و اگر تکرار شد با پشتیبانی تماس بگیرید.',
            'expired' => 'کد منقضی شده است. کد تازه بگیرید.',
            'locked_out' => 'سه بار اشتباه وارد شد. کد تازه بگیرید.',
            'wrong_code' => 'کد وارد‌شده درست نیست.',
            default => 'خطایی رخ داد. دوباره تلاش کنید.',
        };
    }
}
