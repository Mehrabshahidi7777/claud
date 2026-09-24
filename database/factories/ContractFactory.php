<?php

namespace Database\Factories;

use App\Enums\ContractKind;
use App\Enums\ContractStatus;
use App\Enums\PartyType;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'kind' => ContractKind::Employment,
            'party_type' => PartyType::Employee,
            'party_name' => 'رضا مرادی',
            'title' => 'قرارداد یک‌ساله',
            'starts_on' => now()->subYear()->toDateString(),
            'expires_on' => now()->addMonths(2)->toDateString(),
            'notice_days' => 60,
            'status' => ContractStatus::Active,
        ];
    }

    public function expiringIn(int $days): static
    {
        return $this->state(['expires_on' => now()->addDays($days)->toDateString()]);
    }

    public function expiredSince(int $days): static
    {
        return $this->state(['expires_on' => now()->subDays($days)->toDateString()]);
    }

    public function licence(): static
    {
        return $this->state([
            'kind' => ContractKind::Licence,
            'party_type' => PartyType::Authority,
            'party_name' => 'سازمان نظام مهندسی',
            'title' => 'گواهینامه صلاحیت پیمانکاری',
            'notice_days' => 45,
        ]);
    }
}
