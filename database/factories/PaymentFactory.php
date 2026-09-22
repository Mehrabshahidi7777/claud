<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'workspace_id' => Workspace::factory(),
            'gateway' => 'fake',
            'amount' => 11_000_000,
            'res_num' => '1405-0001-'.Str::lower(Str::random(8)),
            'status' => PaymentStatus::Pending,
        ];
    }
}
