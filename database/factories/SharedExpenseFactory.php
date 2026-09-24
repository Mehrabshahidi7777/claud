<?php

namespace Database\Factories;

use App\Models\SharedExpense;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedExpense>
 */
class SharedExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'payer_id' => User::factory(),
            'title' => 'شام رستوران',
            'amount' => 4_500_000,
            'spent_on' => now()->toDateString(),
        ];
    }
}
