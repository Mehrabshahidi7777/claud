<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'timezone' => 'Asia/Tehran',
            'sms_enabled' => true,
            'sms_quota' => 500,
            'sms_used' => 0,
            'sms_period_started_at' => now()->startOfMonth(),
        ];
    }

    public function withoutSmsCredit(): static
    {
        return $this->state(fn (array $attributes) => [
            'sms_used' => $attributes['sms_quota'] ?? 500,
        ]);
    }

    public function smsDisabled(): static
    {
        return $this->state(fn () => ['sms_enabled' => false]);
    }
}
