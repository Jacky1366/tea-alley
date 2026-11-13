<?php

namespace App\Models;

use Database\Factories\OrderItemModifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends Model
{
    /** @use HasFactory<OrderItemModifierFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'modifier_option_id',
        'group_name_en',
        'group_name_zh',
        'name_en',
        'name_zh',
        'price_delta_cents',
    ];

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<ModifierOption, $this>
     */
    public function modifierOption(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class);
    }
}
