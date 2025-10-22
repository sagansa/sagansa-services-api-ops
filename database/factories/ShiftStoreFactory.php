<?php

namespace Database\Factories;

use App\Models\ShiftStore;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftStore>
 */
class ShiftStoreFactory extends Factory
{
    protected $model = ShiftStore::class;

    public function definition(): array
    {
        $startHour = $this->faker->numberBetween(6, 12);
        $durationHours = $this->faker->numberBetween(6, 10);
        $endHour = ($startHour + $durationHours) % 24;

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->words(2, true) . ' Shift',
            'shift_start_time' => sprintf('%02d:00', $startHour),
            'shift_end_time' => sprintf('%02d:00', $endHour),
            'duration' => $durationHours * 60,
        ];
    }
}
