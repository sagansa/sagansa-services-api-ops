<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,  // override in test
            'store_id' => null,   // override in test
            'status' => 'completed',
            'order_type' => 'sale',
            'subtotal' => 0,
            'grand_total' => 0,
            'customer_name' => $this->faker->optional()->name(),
        ];
    }
}
