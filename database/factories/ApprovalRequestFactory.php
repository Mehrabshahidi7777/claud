<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'requester_id' => User::factory(),
            'type' => ApprovalType::Leave,
            'status' => ApprovalStatus::Pending,
            'title' => 'مرخصی استحقاقی',
            'reason' => 'سفر خانوادگی',
            'starts_on' => now()->addDays(3)->toDateString(),
            'ends_on' => now()->addDays(5)->toDateString(),
        ];
    }

    public function purchase(int $amount = 12_000_000): static
    {
        return $this->state([
            'type' => ApprovalType::Purchase,
            'title' => 'خرید لپ‌تاپ',
            'amount' => $amount,
            'starts_on' => null,
            'ends_on' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => ApprovalStatus::Approved,
            'decided_at' => now(),
        ]);
    }
}
