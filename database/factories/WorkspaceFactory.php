<?php

namespace Database\Factories;

use App\Enums\WorkspaceType;
use App\Models\Workspace;
use App\Services\BillingService;
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

    /**
     * Every workspace created through onboarding starts a trial, so one is
     * created here too. Without it the follow-up engine would refuse to send
     * for a factory-made workspace and every engine test would be testing the
     * paywall instead of the ladder.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Workspace $workspace) {
            app(BillingService::class)->startTrial($workspace);
        });
    }

    /**
     * A workspace with no subscription — what a billing test needs so it can
     * set up its own. The trial is removed rather than skipped because
     * afterCreating callbacks stack rather than replace one another.
     */
    /** A family or friends workspace, which gets a different set of modules. */
    public function type(WorkspaceType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function withoutSubscription(): static
    {
        return $this->afterCreating(function (Workspace $workspace) {
            $workspace->subscriptions()->delete();
        });
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
