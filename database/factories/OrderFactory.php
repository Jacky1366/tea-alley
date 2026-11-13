<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(500, 3000);
        $gst = intdiv($subtotal * 5 + 50, 100);

        return [
            'order_number' => 'TA-'.fake()->unique()->numberBetween(1000, 999999),
            'user_id' => User::factory(),
            'promo_code_id' => null,
            'promo_code' => null,
            'status' => OrderStatus::PendingPayment,
            'pickup_type' => PickupType::Asap,
            'pickup_at' => null,
            'subtotal_cents' => $subtotal,
            'discount_cents' => 0,
            'gst_cents' => $gst,
            'pst_cents' => 0,
            'total_cents' => $subtotal + $gst,
            'idempotency_key' => Str::random(64),
            'stripe_session_id' => null,
        ];
    }

    public function scheduled(): static
    {
        return $this->state([
            'pickup_type' => PickupType::Scheduled,
            'pickup_at' => now()->addHour(),
        ]);
    }

    public function paid(): static
    {
        return $this->state([
            'status' => OrderStatus::Paid,
            'stripe_session_id' => 'cs_test_'.Str::random(24),
            'paid_at' => now(),
        ]);
    }
}
