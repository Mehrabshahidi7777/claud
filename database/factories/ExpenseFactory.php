<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'category' => ExpenseCategory::Purchase,
            'title' => 'خرید دستگاه جوش',
            'vendor' => 'فروشگاه سام',
            'amount' => 18_500_000,
            'spent_on' => now()->toDateString(),
        ];
    }

    public function category(ExpenseCategory $category, int $amount): static
    {
        return $this->state(['category' => $category, 'amount' => $amount]);
    }
}
