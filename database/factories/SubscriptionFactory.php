<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'plan_key' => 'corporate',
            'seats' => 5,
            'term' => 'monthly',
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
        ];
    }

    public function expiringIn(int $days): static
    {
        return $this->state(fn () => ['ends_at' => now()->addDays($days)]);
    }

    public function trialing(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Trialing,
            'ends_at' => now()->addDays(14),
        ]);
    }

    public function inGrace(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Grace,
            'ends_at' => now()->subDay(),
            'grace_ends_at' => now()->addDays(6),
        ]);
    }
}
