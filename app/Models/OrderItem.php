<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'name_en',
        'name_zh',
        'tax_category',
        'unit_price_cents',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * @return HasMany<OrderItemModifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    /**
     * The chosen options grouped by modifier group, from snapshot columns only:
     * ["Size: Large", "Toppings: Pearls, Pudding"].
     *
     * The snapshot has no group id or sort order. OrderService inserts modifiers in
     * display order (groups, then options, by sort_order), so load them ordered by id;
     * consecutive rows with the same group name form one group.
     *
     * @return list<string>
     */
    public function modifierSummary(): array
    {
        $lines = [];
        $group = null;
        $options = [];

        foreach ($this->modifiers as $modifier) {
            if ($group !== null && $modifier->group_name_en !== $group) {
                $lines[] = $group.': '.implode(', ', $options);
                $options = [];
            }

            $group = $modifier->group_name_en;
            $options[] = $modifier->name_en;
        }

        if ($group !== null) {
            $lines[] = $group.': '.implode(', ', $options);
        }

        return $lines;
    }

    /**
     * Display only: unit price times quantity, for the receipt line. The order's stored
     * amounts (calculated by PricingService) are the source of truth.
     */
    public function displayLineTotalCents(): int
    {
        return $this->unit_price_cents * $this->quantity;
    }
}
