<?php

namespace App\Services;

use App\Enums\WorkspaceType;
use App\Models\RecurringTask;
use App\Models\SharedExpense;
use App\Models\Task;
use App\Models\Workspace;

/**
 * The short checklist a new workspace sees on its dashboard.
 *
 * Three steps, each one a thing that makes the product useful rather than a
 * tour of it: a task that will actually be chased, a second person for it to
 * chase, and the one feature that makes this plan worth paying for. Each is
 * ticked off by what exists in the database, not by a click, so doing the
 * work somewhere else still counts.
 */
class GettingStarted
{
    public const DISMISSED_SETTING = 'getting_started_dismissed';

    /**
     * The steps, or null once they are all done or the owner has hidden them.
     *
     * @return list<array{label: string, hint: string, url: string, done: bool}>|null
     */
    public function stepsFor(Workspace $workspace): ?array
    {
        if (data_get($workspace->settings, self::DISMISSED_SETTING)) {
            return null;
        }

        $steps = [[
            'label' => 'اولین کار را ثبت کنید',
            'hint' => 'چه کاری، به عهده‌ی چه کسی، تا کی.',
            'url' => route('tasks.index'),
            'done' => Task::where('workspace_id', $workspace->id)->exists(),
        ]];

        if ($workspace->has('members')) {
            $steps[] = [
                'label' => match ($workspace->type) {
                    WorkspaceType::Family => 'یک عضو خانواده اضافه کنید',
                    WorkspaceType::Friends => 'یک دوست اضافه کنید',
                    default => 'یک همکار اضافه کنید',
                },
                'hint' => 'پیگیری وقتی معنا دارد که کسی باشد تا کار را به او بسپارید.',
                'url' => route('members.index'),
                'done' => $workspace->members()->count() > 1,
            ];
        }

        if ($workspace->has('settlements')) {
            $steps[] = [
                'label' => 'اولین خرج مشترک را ثبت کنید',
                'hint' => 'سهم هر کس خودش حساب می‌شود.',
                'url' => route('settlements.index'),
                'done' => SharedExpense::where('workspace_id', $workspace->id)->exists(),
            ];
        } elseif ($workspace->has('recurring')) {
            $steps[] = [
                'label' => 'یک کار تکراری بسازید',
                'hint' => $workspace->type === WorkspaceType::Family
                    ? 'مثل قبض برق یا سرویس ماشین؛ هر بار خودش ساخته می‌شود.'
                    : 'مثل تمدید بیمه یا سرویس دوره‌ای؛ هر بار خودش ساخته می‌شود.',
                'url' => route('recurring.index'),
                'done' => RecurringTask::where('workspace_id', $workspace->id)->exists(),
            ];
        }

        $allDone = collect($steps)->every(fn (array $step): bool => $step['done']);

        return $allDone ? null : $steps;
    }

    public function dismiss(Workspace $workspace): void
    {
        $workspace->update([
            'settings' => array_merge($workspace->settings ?? [], [self::DISMISSED_SETTING => true]),
        ]);
    }
}
