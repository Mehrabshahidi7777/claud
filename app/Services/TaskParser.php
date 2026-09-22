<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Enums\TaskPriority;
use App\Models\Workspace;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * Turns a paragraph a manager typed into draft tasks.
 *
 * Two rules hold throughout. The model's answer is never trusted: it is
 * validated against the schema, names are matched against real members, and
 * dates are bounded before anything is shown. And the result is always a
 * draft — the manager confirms it before a task exists, because a follow-up
 * engine acting on a hallucinated deadline is worse than no engine.
 */
class TaskParser
{
    public function __construct(private readonly AiProvider $ai) {}

    /**
     * @return array{tasks: array<int, array<string, mixed>>, used_ai: bool}
     */
    public function parse(string $text, Workspace $workspace): array
    {
        $members = $workspace->members()->get(['users.id', 'users.name']);

        $result = $this->ai->structured(
            $this->systemPrompt($members->pluck('name')->all()),
            $text,
            $this->schema(),
        );

        if ($result === null) {
            return ['tasks' => [], 'used_ai' => false];
        }

        $tasks = collect($result['tasks'] ?? [])
            ->map(fn ($raw) => $this->sanitize($raw, $members, $workspace))
            ->filter()
            ->take(10) // A paragraph that yields more than ten tasks was misread.
            ->values()
            ->all();

        return ['tasks' => $tasks, 'used_ai' => true];
    }

    /**
     * @param  list<string>  $memberNames
     */
    private function systemPrompt(array $memberNames): string
    {
        $today = JalaliDate::format(CarbonImmutable::now());
        $names = implode('، ', $memberNames) ?: 'هیچ';

        return <<<PROMPT
        تو دستیار استخراج وظیفه از متن فارسی هستی. از متن کاربر، وظیفه‌ها را بیرون بکش.

        امروز به تاریخ شمسی: {$today}
        اعضای تیم: {$names}

        قواعد:
        - فقط وظیفه‌هایی را بیرون بکش که واقعاً در متن آمده‌اند. چیزی از خودت اضافه نکن.
        - عنوان هر وظیفه را کوتاه و عملی بنویس، حداکثر ۶۰ کاراکتر.
        - assignee باید دقیقاً یکی از نام‌های اعضای تیم باشد، وگرنه خالی بگذار.
        - due_date را به تاریخ شمسی و در قالب YYYY/MM/DD بنویس. اگر متن تاریخ ندارد، خالی بگذار.
        - «فردا»، «پس‌فردا»، «هفته بعد» و مانند آن را نسبت به امروز حساب کن.
        - priority یکی از این‌ها: low، normal، high، critical. اگر مشخص نیست normal بگذار.
        - اگر متن هیچ وظیفه‌ای ندارد، آرایه‌ی خالی برگردان.
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
                'tasks' => [
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
            'required' => ['tasks'],
        ];
    }

    /**
     * Everything the model said, checked against what is actually true.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function sanitize(mixed $raw, $members, Workspace $workspace): ?array
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

        // A name the model invented resolves to nobody rather than to whoever
        // it happens to resemble.
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
     * A date is accepted only when it parses as a real Jalali date and lands
     * inside a plausible window. A model asked for "next week" will sometimes
     * answer with a year in the past or five years out, and the follow-up
     * engine would act on either.
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

        $tooEarly = CarbonImmutable::now()->subDays(1);
        $tooLate = CarbonImmutable::now()->addYears(2);

        if ($date < $tooEarly || $date > $tooLate) {
            return null;
        }

        return JalaliDate::format($date);
    }
}
