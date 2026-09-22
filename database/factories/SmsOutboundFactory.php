<?php

namespace Database\Factories;

use App\Models\SmsOutbound;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsOutbound>
 */
class SmsOutboundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'phone' => '98912'.fake()->unique()->numerify('#######'),
            'pattern_key' => 'chase',
            'pattern_code' => 'P-CHASE',
            'tokens' => ['name' => 'علی', 'title' => 'نصب کولر'],
            'rendered_preview' => 'علی، سررسید: نصب کولر',
            'segments' => 1,
            'status' => 'sent',
            'sent_at' => now(),
        ];
    }
}
