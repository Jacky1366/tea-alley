<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Support\OptionSummary;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory, HasLocalizedName;

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
     * The chosen options grouped by modifier group, from snapshot columns only, in the
     * current language: ["Size: Large", "Toppings: Pearls, Pudding"] or
     * ["容量：大杯", "加料：珍珠、布丁"].
     *
     * The snapshot has no group id or sort order. OrderService inserts modifiers in
     * display order (groups, then options, by sort_order), so load them ordered by id;
     * consecutive rows with the same English group name form one group.
     *
     * @return list<string>
     */
    public function modifierSummary(): array
    {
        $lines = [];
        $groupKey = null;
        $groupName = '';
        $options = [];

        foreach ($this->modifiers as $modifier) {
            if ($groupKey !== null && $modifier->group_name_en !== $groupKey) {
                $lines[] = OptionSummary::line($groupName, $options);
                $options = [];
            }

            $groupKey = $modifier->group_name_en;
            $groupName = $modifier->localizedGroupName();
            $options[] = $modifier->localizedName();
        }

        if ($groupKey !== null) {
            $lines[] = OptionSummary::line($groupName, $options);
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
