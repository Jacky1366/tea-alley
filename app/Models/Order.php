<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'promo_code_id',
        'promo_code',
        'status',
        'pickup_type',
        'pickup_at',
        'subtotal_cents',
        'discount_cents',
        'gst_cents',
        'pst_cents',
        'total_cents',
        'idempotency_key',
        'stripe_session_id',
        'paid_at',
        'started_at',
        'ready_at',
        'picked_up_at',
        'cancelled_at',
    ];

    /**
     * Mirrors the database defaults so they can be read right after create().
     * Strict mode throws on attributes that were never loaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending_payment',
        'discount_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'pickup_type' => PickupType::class,
            'pickup_at' => 'datetime',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'gst_cents' => 'integer',
            'pst_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
