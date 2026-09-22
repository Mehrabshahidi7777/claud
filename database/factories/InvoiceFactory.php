<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        // 5 seats × 2,000,000 Rial + 10% VAT.
        $subtotal = 10_000_000;
        $vat = 1_000_000;

        return [
            'workspace_id' => Workspace::factory(),
            'number' => '1405-'.Str::padLeft((string) fake()->unique()->numberBetween(1, 9999), 4, '0'),
            'plan_key' => 'corporate',
            'seats' => 5,
            'term' => 'monthly',
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => $subtotal + $vat,
            'vat_percent' => 10,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'status' => InvoiceStatus::Unpaid,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
