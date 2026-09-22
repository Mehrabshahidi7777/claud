<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Enums\TaskPriority;
use App\Models\Meeting;
use App\Models\Workspace;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * Meeting notes in, action items out.
 *
 * Different work from the one-line capture in TaskParser: notes are long,
 * full of discussion that is not a commitment, and the names in them are
 * spoken rather than typed. The prompt leans hard on the difference, because
 * a model left to itself turns "we should look at the pricing" into a task
 * nobody agreed to — and then the follow-up engine starts chasing someone
 * about it.
 *
 * Everything the model says is still checked: names against real members,
 * dates against the calendar and a plausible window, and the whole thing
 * comes back as a draft the manager confirms.
 */
class MeetingParser
{
    /** A paragraph that yields more than this many commitments was misread. */
    private const MAX_ACTIONS = 15;

    public function __construct(private readonly AiProvider $ai) {}

    /**
     * @return array{summary: ?string, decisions: list<string>, actions: array<int, array<string, mixed>>, used_ai: bool}
     */
    public function parse(Meeting $meeting, Workspace $workspace): array
    {
        $members = $workspace->members()->get(['users.id', 'users.name']);

        $result = $this->ai->structured(
            $this->systemPrompt($members->pluck('name')->all()),
            $meeting->notes,
            $this->schema(),
            purpose: 'writing',
        );

        if ($result === null) {
            return ['summary' => null, 'decisions' => [], 'actions' => [], 'used_ai' => false];
        }

        return [
            'summary' => $this->cleanSummary($result['summary'] ?? null),
            'decisions' => $this->cleanDecisions($result['decisions'] ?? []),
            'actions' => collect($result['actions'] ?? [])
                ->map(fn ($raw) => $this->sanitize($raw, $members))
                ->filter()
                ->take(self::MAX_ACTIONS)
                ->values()
                ->all(),
            'used_ai' => true,
        ];
    }

    /**
     * @param  list<string>  $memberNames
     */
    private function systemPrompt(array $memberNames): string
    {
        $today = JalaliDate::format(CarbonImmutable::now());
        $names = implode('، ', $memberNames) ?: 'هیچ';

        return <<<PROMPT
        تو دستیار صورتجلسه هستی. از متن جلسه سه چیز بیرون بکش: خلاصه، تصمیم‌ها، و اقدام‌ها.

        امروز به تاریخ شمسی: {$today}
        اعضای تیم: {$names}

        مهم‌ترین قاعده:
        - فقط چیزی را «اقدام» بنویس که کسی صریحاً قبول کرده انجام دهد.
        - «باید یک فکری بکنیم»، «خوب است بررسی شود» و بحث بدون تعهد، اقدام نیست.
        - اگر شک داری، ننویس. یک اقدام جا افتاده بهتر از اقدامی است که کسی قبولش نکرده.

        بقیه‌ی قواعد:
        - خلاصه سه تا پنج جمله‌ی فارسی روان باشد و با مهم‌ترین نکته شروع شود.
        - تصمیم‌ها جمله‌های کوتاه و قطعی باشند، نه بحث.
        - عنوان هر اقدام کوتاه و عملی، حداکثر ۶۰ کاراکتر.
        - assignee باید دقیقاً یکی از نام‌های اعضای تیم باشد، وگرنه خالی بگذار.
        - due_date به شمسی و در قالب YYYY/MM/DD. اگر تاریخی گفته نشده، خالی بگذار.
        - «فردا»، «پنجشنبه»، «هفته بعد» را نسبت به امروز حساب کن.
        - priority یکی از: low، normal، high، critical.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'decisions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'assignee' => ['type' => 'string'],
                            'due_date' => ['type' => 'string'],
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['low', 'normal', 'high', 'critical'],
                            ],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
            'required' => ['summary', 'actions'],
        ];
    }

    private function cleanSummary(mixed $summary): ?string
    {
        if (! is_string($summary)) {
            return null;
        }

        $summary = trim($summary);

        // A word is not a summary and a page is not one either; both mean the
        // model misread the job, and the notes are still there to read.
        return mb_strlen($summary) >= 30 && mb_strlen($summary) <= 1200 ? $summary : null;
    }

    /**
     * @return list<string>
     */
    private function cleanDecisions(mixed $decisions): array
    {
        if (! is_array($decisions)) {
            return [];
        }

        return collect($decisions)
            ->filter(fn ($decision) => is_string($decision) && mb_strlen(trim($decision)) >= 5)
            ->map(fn (string $decision) => trim($decision))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitize(mixed $raw, $members): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $validator = Validator::make($raw, [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:100'],
            'due_date' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return null;
        }

        $clean = $validator->validated();

        // A name the model invented resolves to nobody. Assigning work to the
        // wrong person is worse than leaving it unassigned, and in a meeting
        // the names are spoken, so near-misses are common.
        $assignee = $members->first(
            fn ($member) => trim($member->name) === trim((string) ($clean['assignee'] ?? ''))
        );

        return [
            'title' => trim($clean['title']),
            'assignee_id' => $assignee?->id,
            'assignee_name' => $assignee?->name,
            'due_date' => $this->resolveDate($clean['due_date'] ?? null),
            'priority' => TaskPriority::tryFrom($clean['priority'] ?? '')?->value
                ?? TaskPriority::Normal->value,
        ];
    }

    /**
     * A date is accepted only when it parses and lands inside a plausible
     * window. Asked for "next week" a model will sometimes answer with a year
     * in the past, and the follow-up engine would act on it.
     */
    private function resolveDate(?string $written): ?string
    {
        if (blank($written)) {
            return null;
        }

        if (! preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', trim($written), $m)) {
            return null;
        }

        $date = JalaliDate::toGregorian((int) $m[1], (int) $m[2], (int) $m[3]);

        if ($date === null) {
            return null;
        }

        if ($date < CarbonImmutable::now()->subDay() || $date > CarbonImmutable::now()->addYears(2)) {
            return null;
        }

        return JalaliDate::format($date);
    }
}
