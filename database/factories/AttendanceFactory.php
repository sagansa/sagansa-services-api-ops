<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        $checkIn = CarbonImmutable::parse($this->faker->dateTimeBetween('-1 week', 'now', config('app.timezone')));
        $checkOut = $checkIn->addHours(random_int(6, 10));

        return [
            'store_id' => Store::factory(),
            'shift_store_id' => null,
            'status' => Attendance::STATUS_PENDING,
            'was_late' => false,
            'image_in' => $this->faker->imageUrl(),
            'check_in' => $checkIn,
            'latitude_in' => $this->faker->latitude(),
            'longitude_in' => $this->faker->longitude(),
            'image_out' => $this->faker->imageUrl(),
            'check_out' => $checkOut,
            'latitude_out' => $this->faker->latitude(),
            'longitude_out' => $this->faker->longitude(),
            'auto_checked_out_at' => null,
            'created_by_id' => User::factory(),
            'approved_by_id' => null,
        ];
    }

    /**
     * Indicate that the attendance entry has been approved.
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => Attendance::STATUS_APPROVED,
            'approved_by_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the attendance entry has been rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => Attendance::STATUS_REJECTED,
            'approved_by_id' => User::factory(),
        ]);
    }
}
