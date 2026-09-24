<?php

namespace Database\Factories;

use App\Enums\ReceivableStatus;
use App\Models\Receivable;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receivable>
 */
class ReceivableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'customer_name' => 'شرکت ساختمانی نگین',
            'title' => 'صورت‌وضعیت شماره ۳',
            'amount' => 420_000_000,
            'settled_amount' => 0,
            'issued_on' => now()->subDays(40)->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
            'status' => ReceivableStatus::Open,
        ];
    }

    /** Past due by a given number of days, which is what the sweep acts on. */
    public function overdueBy(int $days): static
    {
        return $this->state([
            'due_on' => now()->subDays($days)->toDateString(),
            'issued_on' => now()->subDays($days + 30)->toDateString(),
        ]);
    }

    public function settled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReceivableStatus::Settled,
            'settled_amount' => $attributes['amount'] ?? 0,
        ]);
    }
}
