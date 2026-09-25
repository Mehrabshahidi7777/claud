<?php

namespace App\Services;

use App\Contracts\SmsDriver;
use App\Models\OtpCode;
use App\Models\User;
use App\Sms\PatternMessage;
use App\Support\PersianText;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phone-number sign-in.
 *
 * Every code sent is a real SMS we pay for, so the rate limits here are not
 * only a security measure: without them a trivial script empties the credit
 * overnight and the follow-up engine goes silent for every customer.
 */
class OtpService
{
    private const CODE_LENGTH = 5;

    private const TTL_SECONDS = 120;

    public function __construct(private readonly SmsDriver $driver) {}

    /**
     * @return array{sent: bool, reason: ?string, seconds_until_retry: ?int}
     */
    public function request(string $rawPhone, ?string $ip = null): array
    {
        $phone = PhoneNumber::normalize($rawPhone);

        if ($phone === null) {
            return $this->refuse('invalid_phone');
        }

        // One code a minute per number, five an hour, and a ceiling per IP so
        // one client cannot walk through a range of numbers.
        if ($wait = $this->throttled("otp:phone:$phone", 1, 60)) {
            return $this->refuse('too_soon', $wait);
        }

        if ($wait = $this->throttled("otp:phone-hourly:$phone", 5, 3600)) {
            return $this->refuse('hourly_limit', $wait);
        }

        // Three guesses a code at five codes an hour is still 360 guesses a
        // day against one number, around ten percent of the code space in a
        // month. The daily ceiling keeps a patient attacker under one percent.
        if ($wait = $this->throttled("otp:phone-daily:$phone", 10, 86400)) {
            return $this->refuse('daily_limit', $wait);
        }

        if ($ip !== null && $wait = $this->throttled("otp:ip:$ip", 10, 3600)) {
            return $this->refuse('ip_limit', $wait);
        }

        $code = $this->generateCode();

        // Any code still outstanding for this number is retired, so only the
        // newest one can log anybody in.
        OtpCode::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'request_ip' => $ip,
        ]);

        $result = $this->driver->send(PatternMessage::make($phone, 'otp', ['code' => $code]));

        // Telling someone the code is on its way when the panel refused it
        // strands them on the code screen with nothing to type. This is the
        // very first thing a new line does wrong — an unapproved pattern, a
        // wrong token, no credit — so it has to be said out loud.
        if (! $result->successful) {
            Log::error('OTP send failed.', ['phone' => $phone, 'error' => $result->error]);

            // The minute-long throttle was for a code they never received, so
            // it is released and they may try again at once.
            RateLimiter::clear("otp:phone:$phone");

            return $this->refuse('delivery_failed');
        }

        return ['sent' => true, 'reason' => null, 'seconds_until_retry' => null];
    }

    /**
     * @return array{verified: bool, user: ?User, is_new: bool, reason: ?string}
     */
    public function verify(string $rawPhone, string $candidate): array
    {
        $phone = PhoneNumber::normalize($rawPhone);

        if ($phone === null) {
            return ['verified' => false, 'user' => null, 'is_new' => false, 'reason' => 'invalid_phone'];
        }

        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($otp === null || $otp->isExpired()) {
            return ['verified' => false, 'user' => null, 'is_new' => false, 'reason' => 'expired'];
        }

        if (! $otp->claimAttempt()) {
            return ['verified' => false, 'user' => null, 'is_new' => false, 'reason' => 'locked_out'];
        }

        // Digits typed on a Persian keyboard arrive as ۱۲۳۴۵ and must still
        // match the Latin code that was sent.
        if (! $otp->matches(PersianText::foldDigits(trim($candidate)))) {
            return ['verified' => false, 'user' => null, 'is_new' => false, 'reason' => 'wrong_code'];
        }

        if (! $otp->consume()) {
            return ['verified' => false, 'user' => null, 'is_new' => false, 'reason' => 'expired'];
        }

        // A member their manager created already exists with an unverified
        // number; signing in for the first time verifies it rather than
        // creating a second account.
        $user = User::where('phone', $phone)->first();
        $isNew = $user === null;

        if ($isNew) {
            $user = User::create(['phone' => $phone, 'name' => '']);
        }

        $user->update(['phone_verified_at' => now()]);

        return ['verified' => true, 'user' => $user, 'is_new' => $isNew, 'reason' => null];
    }

    /**
     * Five digits rather than six: the extra digit adds little against a
     * two-minute window guarded by three attempts, and costs real accuracy
     * when someone is reading it off a lock screen.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 99999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function throttled(string $key, int $max, int $decaySeconds): ?int
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return RateLimiter::availableIn($key);
        }

        RateLimiter::hit($key, $decaySeconds);

        return null;
    }

    /**
     * @return array{sent: false, reason: string, seconds_until_retry: ?int}
     */
    private function refuse(string $reason, ?int $seconds = null): array
    {
        return ['sent' => false, 'reason' => $reason, 'seconds_until_retry' => $seconds];
    }
}
