<?php

namespace Database\Factories;

use App\Models\ModifierOption;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItemModifier>
 */
class OrderItemModifierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'modifier_option_id' => null,
            'group_name_en' => 'Toppings',
            'group_name_zh' => '配料',
            'name_en' => Str::title(fake()->word()),
            'name_zh' => '配料'.fake()->numberBetween(1, 999),
            'price_delta_cents' => fake()->randomElement([0, 50, 75, 100]),
        ];
    }

    /**
     * Link to a modifier option and snapshot its group, name, and price delta.
     */
    public function forOption(ModifierOption $option): static
    {
        $option->loadMissing('modifierGroup');

        return $this->state([
            'modifier_option_id' => $option->id,
            'group_name_en' => $option->modifierGroup->name_en,
            'group_name_zh' => $option->modifierGroup->name_zh,
            'name_en' => $option->name_en,
            'name_zh' => $option->name_zh,
            'price_delta_cents' => $option->price_delta_cents,
        ]);
    }
}
