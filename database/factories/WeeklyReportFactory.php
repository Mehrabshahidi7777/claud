<?php

namespace Database\Factories;

use App\Models\WeeklyReport;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyReport>
 */
class WeeklyReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
            'metrics' => [
                'headline' => ['on_time_rate' => 71.4, 'chase_response_rate' => 60.0, 'escalation_ratio' => 20.0],
                'change' => ['on_time_rate' => 3.2, 'chase_response_rate' => null, 'escalation_ratio' => -4.0],
                'counts' => ['created' => 12, 'closed' => 7, 'overdue_now' => 2, 'escalated' => 1],
                'overdue' => [],
                'at_risk' => [],
                'by_member' => [],
                'sms' => ['sent' => 9, 'used' => 46, 'remaining' => 454],
            ],
            'narrative' => 'نرخ تکمیل به‌موقع این هفته ۷۱ درصد بود.',
            'narrative_from_ai' => false,
        ];
    }
}
