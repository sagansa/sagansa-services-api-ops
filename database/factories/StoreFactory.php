<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->company() . ' Store',
            'nickname' => $this->faker->companySuffix(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'email' => $this->faker->unique()->companyEmail(),
            'status' => 'active',
            'radius' => 100,
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
        ];
    }
}
