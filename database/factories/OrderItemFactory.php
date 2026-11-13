<?php

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'menu_item_id' => null,
            'name_en' => Str::title(fake()->word().' Milk Tea'),
            'name_zh' => '飲品'.fake()->numberBetween(1, 999),
            'tax_category' => MenuItem::TAX_STANDARD,
            'unit_price_cents' => fake()->numberBetween(450, 800),
            'quantity' => fake()->numberBetween(1, 3),
        ];
    }

    /**
     * Link to a menu item and snapshot its name, tax category, and price.
     */
    public function forMenuItem(MenuItem $item): static
    {
        return $this->state([
            'menu_item_id' => $item->id,
            'name_en' => $item->name_en,
            'name_zh' => $item->name_zh,
            'tax_category' => $item->tax_category,
            'unit_price_cents' => $item->price_cents,
        ]);
    }
}
