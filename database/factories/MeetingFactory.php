<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'title' => 'جلسه هفتگی عملیات',
            'held_at' => now(),
            'notes' => 'رضا تا پنجشنبه گزارش سرویس‌ها را آماده کند. حسین فردا با کارفرما تماس بگیرد.',
        ];
    }
}
