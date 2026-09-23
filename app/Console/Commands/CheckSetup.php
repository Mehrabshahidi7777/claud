<?php

namespace App\Console\Commands;

use App\Contracts\AiProvider;
use App\Contracts\SmsDriver;
use App\Models\Holiday;
use App\Models\TaskFollowUp;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Is it ready?" answered in one screen.
 *
 * Every line below is something that has silently swallowed a real message on
 * a real deployment: a pattern registered but never pasted into .env, a token
 * that works in the panel and not over the API, a cron nobody added. The
 * engine is designed to fail loudly, but it can only fail when something tries
 * to send — and the first thing that tries to send is a paying customer's
 * deadline.
 *
 *     php artisan app:check
 */
class CheckSetup extends Command
{
    protected $signature = 'app:check {--credit : Also ask the panel for the remaining credit}';

    protected $description = 'Report what is configured, what is missing, and what will silently not work';

    private bool $blocking = false;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>وضعیت راه‌اندازی</>');

        $this->checkDatabase();
        $this->checkSms();
        $this->checkPatterns();
        $this->checkPayment();
        $this->checkAi();
        $this->checkSchedule();

        $this->newLine();

        if ($this->blocking) {
            $this->line('  <fg=red;options=bold>موارد قرمز باید درست شوند.</> موارد زرد فعلاً اشکالی ندارند.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('  <fg=green;options=bold>آماده است.</> موارد زرد فقط هشدارند.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function checkDatabase(): void
    {
        $this->section('دیتابیس');

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->bad('اتصال', $e->getMessage());

            return;
        }

        $this->good('اتصال', config('database.default').' · '.config('database.connections.'.config('database.default').'.database'));

        // Persian text and emoji survive only on utf8mb4. A wrong collation
        // does not error — it mangles, months later, in the archive.
        if (config('database.default') === 'mysql') {
            $charset = config('database.connections.mysql.charset');

            $charset === 'utf8mb4'
                ? $this->good('کدگذاری', 'utf8mb4')
                : $this->bad('کدگذاری', "«$charset» — متن فارسی خراب می‌شود، باید utf8mb4 باشد");
        }

        $workspaces = Workspace::count();

        $workspaces > 0
            ? $this->good('فضاهای کاری', (string) $workspaces)
            : $this->warn2('فضاهای کاری', 'هیچ — با php artisan db:seed --class=DemoSeeder یکی بسازید');
    }

    private function checkSms(): void
    {
        $this->section('پیامک');

        $driver = config('sms.driver');

        match ($driver) {
            'amoot' => $this->good('درایور', 'amoot — ارسال واقعی'),
            'log' => $this->warn2('درایور', 'log — پیامک فقط در لاگ نوشته می‌شود، چیزی ارسال نمی‌شود'),
            'fake' => $this->warn2('درایور', 'fake — فقط برای تست'),
            default => $this->bad('درایور', "«$driver» ناشناخته است"),
        };

        if (! config('sms.enabled', true)) {
            $this->warn2('کلید اصلی', 'SMS_ENABLED=false — هیچ پیامکی از هیچ فضای کاری بیرون نمی‌رود');
        }

        if ($driver !== 'amoot') {
            return;
        }

        filled(config('sms.amoot.token'))
            ? $this->good('توکن', 'تنظیم شده')
            : $this->bad('توکن', 'AMOOT_TOKEN خالی است');

        filled(config('sms.amoot.line_number'))
            ? $this->good('خط اختصاصی', (string) config('sms.amoot.line_number'))
            : $this->bad('خط اختصاصی', 'AMOOT_LINE_NUMBER خالی است');

        $secret = (string) config('sms.amoot.inbound_secret');

        match (true) {
            $secret === '' => $this->bad('رمز وب‌هوک', 'AMOOT_INBOUND_SECRET خالی است — پاسخ‌های ورودی رد می‌شوند'),
            strlen($secret) < 24 => $this->warn2('رمز وب‌هوک', 'کوتاه است؛ با bin2hex(random_bytes(24)) بسازید'),
            default => $this->good('رمز وب‌هوک', 'تنظیم شده'),
        };

        if ($this->option('credit')) {
            $credit = app(SmsDriver::class)->credit();

            $credit === null
                ? $this->bad('اعتبار پنل', 'پاسخی نگرفتیم — توکن یا دسترسی API را بررسی کنید')
                : $this->good('اعتبار پنل', (string) $credit);
        }
    }

    /**
     * Which templates will actually deliver. A pattern with no code is refused
     * before the request goes out, so this is the difference between a working
     * feature and one that fails on its first real use.
     */
    private function checkPatterns(): void
    {
        $this->section('پترن‌ها');

        $labels = [
            'otp' => 'ورود با پیامک',
            'chase' => 'پیگیری سررسید',
            'defer_ask' => 'درخواست تاریخ جدید',
            'escalate' => 'تشدید به مدیر',
            'confirm_done' => 'تأیید انجام',
            'confirm_defer' => 'تأیید تعویق',
            'unknown' => 'پاسخ نامفهوم',
            'welcome' => 'خوش‌آمد عضو جدید',
            'weekly_report' => 'گزارش هفتگی',
            'subscription_expiring' => 'یادآوری تمدید',
            'approval_request' => 'درخواست تأییدیه',
            'approval_decision' => 'نتیجه تأییدیه',
        ];

        $missing = [];

        foreach ($labels as $key => $label) {
            if (blank(config("sms.patterns.$key.code"))) {
                $missing[] = $label;
            }
        }

        $total = count($labels);
        $registered = $total - count($missing);

        if ($missing === []) {
            $this->good('ثبت‌شده', "$total از $total");

            return;
        }

        // Sign-in is the one that stops everything: without it nobody can get
        // in at all. The rest degrade one feature at a time, so they are a
        // warning — and only on the real driver, since `log` sends nothing
        // either way.
        if (blank(config('sms.patterns.otp.code'))) {
            config('sms.driver') === 'amoot'
                ? $this->bad('ورود با پیامک', 'پترن otp ثبت نشده — هیچ‌کس نمی‌تواند وارد شود')
                : $this->warn2('ورود با پیامک', 'پترن otp ثبت نشده — پیش از سوییچ به amoot لازم است');
        } else {
            $this->good('ورود با پیامک', 'ثبت شده');
        }

        $this->warn2("ثبت‌نشده ($registered از $total)", implode('، ', $missing));
    }

    private function checkPayment(): void
    {
        $this->section('درگاه پرداخت');

        $gateway = config('payment.gateway');

        if ($gateway !== 'sep') {
            $this->warn2('درگاه', "$gateway — پولی جابه‌جا نمی‌شود");

            return;
        }

        filled(config('payment.sep.terminal_id'))
            ? $this->good('ترمینال', (string) config('payment.sep.terminal_id'))
            : $this->bad('ترمینال', 'SEP_TERMINAL_ID خالی است');

        $url = rtrim((string) config('app.url'), '/').'/billing/callback';

        str_starts_with($url, 'https://')
            ? $this->good('آدرس بازگشت', $url)
            : $this->bad('آدرس بازگشت', "$url — باید https باشد و در پنل سامان همین ثبت شود");
    }

    private function checkAi(): void
    {
        $this->section('هوش مصنوعی');

        $provider = (string) config('ai.provider');

        // "null" is the sentinel for "disabled", not a missing value.
        if ($provider === '' || $provider === 'null') {
            $this->warn2('ارائه‌دهنده', 'null — ثبت سریع و صورتجلسه به فرم دستی برمی‌گردند');

            return;
        }

        $this->good('ارائه‌دهنده', $provider);
        $this->good('مدل‌ها', config('ai.ollama.models.extraction').' · '.config('ai.ollama.models.writing'));

        app(AiProvider::class)->isAvailable()
            ? $this->good('در دسترس', (string) config('ai.ollama.base_url'))
            : $this->warn2('در دسترس', 'پاسخ نمی‌دهد — همه‌چیز به حالت دستی برمی‌گردد');
    }

    /**
     * Without the cron nothing in this product happens on its own, which makes
     * it the single most expensive line to get wrong: everything looks fine
     * until the first deadline passes in silence.
     */
    private function checkSchedule(): void
    {
        $this->section('زمان‌بندی');

        $overdue = TaskFollowUp::query()->due()->count();

        match (true) {
            $overdue === 0 => $this->good('پیگیری‌های سررسیدشده', 'صفر'),
            $overdue < 5 => $this->warn2('پیگیری‌های سررسیدشده', "$overdue مورد منتظرند"),
            default => $this->bad(
                'پیگیری‌های سررسیدشده',
                "$overdue مورد انباشته شده — احتمالاً کرون سرور اجرا نمی‌شود",
            ),
        };

        Holiday::count() > 0
            ? $this->good('تعطیلات رسمی', Holiday::count().' روز')
            : $this->warn2('تعطیلات رسمی', 'خالی — با php artisan holidays:seed پر کنید');
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <fg=gray>$title</>");
    }

    private function good(string $label, string $detail): void
    {
        $this->line("  <fg=green>✓</> $label  <fg=gray>$detail</>");
    }

    private function warn2(string $label, string $detail): void
    {
        $this->line("  <fg=yellow>!</> $label  <fg=gray>$detail</>");
    }

    private function bad(string $label, string $detail): void
    {
        $this->blocking = true;
        $this->line("  <fg=red>✗</> $label  <fg=gray>$detail</>");
    }
}
