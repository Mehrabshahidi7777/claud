<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Models\Workspace;

/**
 * Writes the paragraph at the top of the weekly report.
 *
 * The local model writes it where one is configured. Where it is not — or
 * where it answers with something unusable — a deterministic composer writes
 * it instead, from the same numbers. The manager cannot tell which produced
 * it, and that is deliberate: the Saturday email has to arrive whether or not
 * anyone remembered to start Ollama.
 */
class WeeklyReportComposer
{
    public function __construct(private readonly AiProvider $ai) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{narrative: string, from_ai: bool}
     */
    public function compose(array $snapshot, Workspace $workspace): array
    {
        $written = $this->askModel($snapshot, $workspace);

        if ($written !== null) {
            return ['narrative' => $written, 'from_ai' => true];
        }

        return ['narrative' => $this->writeFromNumbers($snapshot), 'from_ai' => false];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function askModel(array $snapshot, Workspace $workspace): ?string
    {
        $answer = $this->ai->structured(
            $this->systemPrompt($workspace),
            json_encode($this->factsForModel($snapshot), JSON_UNESCAPED_UNICODE) ?: '{}',
            [
                'type' => 'object',
                'properties' => ['summary' => ['type' => 'string']],
                'required' => ['summary'],
            ],
            // Prose a manager will read, not a JSON extraction — worth the
            // larger model, and it runs once a week rather than on every paste.
            purpose: 'writing',
        );

        $summary = is_array($answer) ? ($answer['summary'] ?? null) : null;

        if (! is_string($summary)) {
            return null;
        }

        $summary = trim($summary);

        // A model that returns one word, or a page, has misunderstood the job.
        // Falling back beats sending a manager something odd once a week. The
        // ceiling is generous because a company report now covers money,
        // contracts and services as well as tasks.
        if (mb_strlen($summary) < 40 || mb_strlen($summary) > 1200) {
            return null;
        }

        return $summary;
    }

    private function systemPrompt(Workspace $workspace): string
    {
        return <<<PROMPT
        تو دستیار گزارش‌نویسی برای مدیرعامل شرکت «{$workspace->name}» هستی.

        از روی اعداد هفته، سه تا پنج جمله‌ی فارسی روان بنویس.

        قواعد:
        - فقط از همین اعداد استفاده کن. هیچ عددی از خودت نساز.
        - با مهم‌ترین نکته شروع کن، نه با مقدمه.
        - اگر وضعیت بدتر شده، صریح بگو. تعارف نکن.
        - اگر کسی بیشترین کار عقب‌افتاده را دارد، نامش را بیاور.
        - جمله‌هایت کوتاه باشد. از «در این گزارش» و «شایان ذکر است» استفاده نکن.
        - خروجی فقط یک پاراگراف است، بدون تیتر و بدون فهرست.
        - اگر مبلغی در اعداد هست، مهم‌تر از شمارش تسک است — اول آن را بگو.
        - مبلغ‌ها ریال‌اند. عدد را دقیقاً همان‌طور که هست بنویس و واحد را «ریال» بگو.
        - درباره‌ی چیزی که در اعداد نیامده، حتی یک کلمه ننویس.
        PROMPT;
    }

    /**
     * What the model is allowed to see: the figures, and nothing that would
     * tempt it to invent detail.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function factsForModel(array $snapshot): array
    {
        return array_merge([
            'نرخ_تکمیل_به_موقع' => $snapshot['headline']['on_time_rate'],
            'تغییر_نسبت_به_هفته_قبل' => $snapshot['change']['on_time_rate'],
            'نرخ_پاسخ_به_پیامک' => $snapshot['headline']['chase_response_rate'],
            'نسبت_تشدید' => $snapshot['headline']['escalation_ratio'],
            'تسک_ساخته_شده' => $snapshot['counts']['created'],
            'تسک_بسته_شده' => $snapshot['counts']['closed'],
            'عقب_افتاده_اکنون' => $snapshot['counts']['overdue_now'],
            'تشدید_شده' => $snapshot['counts']['escalated'],
            'بیشترین_عقب_افتادگی' => collect($snapshot['by_member'])
                ->sortByDesc('overdue')
                ->take(3)
                ->map(fn ($row) => ['نام' => $row['name'], 'عقب_افتاده' => $row['overdue']])
                ->values()
                ->all(),
            'در_خطر_تا_سه_روز_آینده' => count($snapshot['at_risk']),
        ], $this->moneyFacts($snapshot));
    }

    /**
     * The sections a company has and a household does not.
     *
     * Kept out of the array entirely rather than passed as nulls: a model
     * handed "درآمد_معوق: null" writes a sentence about it, and a family
     * receiving a paragraph about receivables has been sent somebody else's
     * report.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function moneyFacts(array $snapshot): array
    {
        $facts = [];

        if (($money = $snapshot['money'] ?? null) !== null) {
            $facts['مطالبات_وصول_نشده_ریال'] = $money['outstanding'];
            $facts['مطالبات_معوق_ریال'] = $money['overdue'];
            $facts['معوق_بدون_پیگیر'] = $money['unchased'];
            $facts['وصول_شده_این_هفته_ریال'] = $money['collected'];
        }

        if (($recurring = $snapshot['recurring'] ?? null) !== null) {
            $facts['سرویس_دوره_ای_عقب_افتاده'] = $recurring['overdue'];
            $facts['درآمد_در_معرض_از_دست_رفتن_ریال'] = $recurring['value_at_risk'];
        }

        if (($contracts = $snapshot['contracts'] ?? null) !== null) {
            $facts['قرارداد_منقضی'] = $contracts['expired'];
            $facts['قرارداد_منقضی_پرهزینه'] = $contracts['serious'];
            $facts['قرارداد_نزدیک_انقضا'] = $contracts['expiring_soon'];
        }

        if (($approvals = $snapshot['approvals'] ?? null) !== null) {
            $facts['درخواست_بی_پاسخ'] = $approvals['pending'];
            $facts['درخواست_بیش_از_یک_هفته_معطل'] = $approvals['stale'];
        }

        return $facts;
    }

    /**
     * The fallback. Built from the same facts in a fixed order, so it reads as
     * a report rather than as an apology for a missing model.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function writeFromNumbers(array $snapshot): string
    {
        $sentences = [];

        $onTime = $snapshot['headline']['on_time_rate'];
        $change = $snapshot['change']['on_time_rate'];

        if ($onTime === null) {
            $sentences[] = 'این هفته کاری بسته نشد، پس نرخ تکمیل به‌موقع قابل محاسبه نیست.';
        } else {
            $direction = match (true) {
                $change === null => '',
                $change > 0 => sprintf('، %s واحد بهتر از هفته قبل', $this->number($change)),
                $change < 0 => sprintf('، %s واحد بدتر از هفته قبل', $this->number(abs($change))),
                default => '، بدون تغییر نسبت به هفته قبل',
            };

            $sentences[] = sprintf(
                'نرخ تکمیل به‌موقع این هفته %s درصد بود%s.',
                $this->number($onTime),
                $direction,
            );
        }

        $sentences[] = sprintf(
            '%s کار ثبت شد و %s کار بسته شد.',
            $this->number($snapshot['counts']['created']),
            $this->number($snapshot['counts']['closed']),
        );

        if ($snapshot['counts']['overdue_now'] > 0) {
            $worst = collect($snapshot['by_member'])->sortByDesc('overdue')->first();

            $sentences[] = $worst !== null && $worst['overdue'] > 0
                ? sprintf(
                    'هم‌اکنون %s کار عقب‌افتاده دارید و بیشترین آن روی %s است.',
                    $this->number($snapshot['counts']['overdue_now']),
                    $worst['name'],
                )
                : sprintf('هم‌اکنون %s کار عقب‌افتاده دارید.', $this->number($snapshot['counts']['overdue_now']));
        } else {
            $sentences[] = 'هیچ کاری عقب نیست.';
        }

        if ($snapshot['counts']['escalated'] > 0) {
            $sentences[] = sprintf(
                '%s کار به مدیر مستقیم تشدید شد.',
                $this->number($snapshot['counts']['escalated']),
            );
        }

        if (($atRisk = count($snapshot['at_risk'])) > 0) {
            $sentences[] = sprintf(
                '%s کار تا سه روز آینده سررسید می‌شود و نیاز به پیگیری دارد.',
                $this->number($atRisk),
            );
        }

        return implode(' ', array_merge($sentences, $this->moneySentences($snapshot)));
    }

    /**
     * The sentences that only exist where the plan does.
     *
     * Ordered by what costs most: money nobody is chasing, then a lapse that
     * carries real exposure, then revenue left on the floor, then people
     * waiting on a decision.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function moneySentences(array $snapshot): array
    {
        $sentences = [];

        if (($money = $snapshot['money'] ?? null) !== null && $money['overdue'] > 0) {
            $sentences[] = $money['unchased'] > 0
                ? sprintf(
                    '%s ریال مطالبه‌ی معوق دارید که %s فقره‌اش هنوز هیچ‌کس پیگیرش نیست.',
                    $this->rial($money['overdue']),
                    $this->number($money['unchased']),
                )
                : sprintf('%s ریال مطالبه‌ی معوق دارید.', $this->rial($money['overdue']));
        }

        if (($contracts = $snapshot['contracts'] ?? null) !== null && $contracts['serious'] > 0) {
            $sentences[] = sprintf(
                '%s قرارداد یا مجوز منقضی شده که رها کردنش گران تمام می‌شود.',
                $this->number($contracts['serious']),
            );
        }

        if (($recurring = $snapshot['recurring'] ?? null) !== null && $recurring['overdue'] > 0) {
            $sentences[] = $recurring['value_at_risk'] > 0
                ? sprintf(
                    '%s سرویس دوره‌ای از تاریخش گذشته — حدود %s ریال درآمدی که هنوز فاکتور نشده.',
                    $this->number($recurring['overdue']),
                    $this->rial($recurring['value_at_risk']),
                )
                : sprintf('%s کار دوره‌ای از تاریخش گذشته.', $this->number($recurring['overdue']));
        }

        if (($approvals = $snapshot['approvals'] ?? null) !== null && $approvals['stale'] > 0) {
            $sentences[] = sprintf(
                '%s درخواست بیش از یک هفته است منتظر تصمیم مانده.',
                $this->number($approvals['stale']),
            );
        }

        return $sentences;
    }

    /**
     * Large sums, grouped so they can be read aloud. A twelve-digit run of
     * Persian digits is a number nobody parses.
     */
    private function rial(int $value): string
    {
        return $this->number(0) === '۰'
            ? strtr(number_format($value), [
                '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
            ])
            : number_format($value);
    }

    /**
     * Persian digits, because the rest of the sentence is Persian and a Latin
     * numeral in the middle of it reads as a copy-paste error.
     */
    private function number(float|int $value): string
    {
        $written = $value == (int) $value ? (string) (int) $value : (string) round($value, 1);

        return strtr($written, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}
