<?php

namespace Database\Factories;

use App\Enums\RecurrenceAnchor;
use App\Enums\RecurrenceUnit;
use App\Models\RecurringTask;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTask>
 */
class RecurringTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'title' => 'سرویس دوره‌ای چیلر',
            'customer_name' => 'مجتمع تجاری الهیه',
            'interval_unit' => RecurrenceUnit::Month,
            'interval_count' => 6,
            'anchor' => RecurrenceAnchor::Completion,
            'lead_days' => 7,
            'next_due_on' => now()->addMonth()->toDateString(),
            'estimated_value' => 85_000_000,
            'is_active' => true,
        ];
    }

    public function dueIn(int $days): static
    {
        return $this->state(['next_due_on' => now()->addDays($days)->toDateString()]);
    }

    /** A household chore rather than a customer contract. */
    public function household(): static
    {
        return $this->state([
            'title' => 'تعویض روغن ماشین',
            'customer_name' => null,
            'estimated_value' => null,
            'interval_unit' => RecurrenceUnit::Month,
            'interval_count' => 4,
        ]);
    }
}
