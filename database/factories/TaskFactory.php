<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => fake()->sentence(3),
            'assignee_id' => User::factory(),
            'creator_id' => User::factory(),
            'due_at' => now()->addDay(),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Open,
            'may_break_quiet_hours' => false,
        ];
    }

    public function overdue(int $hours = 3): static
    {
        return $this->state(fn () => ['due_at' => now()->subHours($hours)]);
    }

    public function critical(bool $mayBreakQuietHours = false): static
    {
        return $this->state(fn () => [
            'priority' => TaskPriority::Critical,
            'may_break_quiet_hours' => $mayBreakQuietHours,
        ]);
    }

    public function lowPriority(): static
    {
        return $this->state(fn () => ['priority' => TaskPriority::Low]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
    }
}
