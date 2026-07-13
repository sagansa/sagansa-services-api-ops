<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'code' => 'test_' . uniqid(),
            'name' => 'Test Plan',
            'pos_rate_percent' => 0.0100,    // 1%
            'pos_base_charge' => 99000,
            'pos_usage_threshold' => 1000,   // Rp1.000
            'attendance_rate' => 5000,
            'attendance_free_count' => 5,
            'trial_months' => 3,
            'is_active' => true,
        ];
    }
}
