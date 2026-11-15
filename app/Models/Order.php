<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Types as cast (Larastan reads the migrations, not casts()). Dates are CarbonImmutable:
 * AppServiceProvider sets Date::use(CarbonImmutable::class).
 *
 * @property int $id
 * @property string $order_number
 * @property int $user_id
 * @property int|null $promo_code_id
 * @property string|null $promo_code
 * @property OrderStatus $status
 * @property PickupType $pickup_type
 * @property CarbonImmutable|null $pickup_at
 * @property int $prep_minutes
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $gst_cents
 * @property int $pst_cents
 * @property int $total_cents
 * @property string $idempotency_key
 * @property string|null $stripe_session_id
 * @property CarbonImmutable|null $payment_expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $picked_up_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int $recalled_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
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
        'prep_minutes',
        'subtotal_cents',
        'discount_cents',
        'gst_cents',
        'pst_cents',
        'total_cents',
        'idempotency_key',
        'stripe_session_id',
        'payment_expires_at',
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
        'prep_minutes' => 0,
        'recalled_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'pickup_type' => PickupType::class,
            'pickup_at' => 'datetime',
            'prep_minutes' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'gst_cents' => 'integer',
            'pst_cents' => 'integer',
            'total_cents' => 'integer',
            'payment_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'recalled_count' => 'integer',
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

    /**
     * Moves the order to another status. Every status change after the order is created
     * goes through here.
     *
     * The write is conditional on the status this model holds (WHERE status = <current>),
     * so two requests can't both make the same move: the second one changes 0 rows and
     * gets OrderTransitionConflict. That relies on every legal move changing `status`:
     * MySQL reports rows changed, not rows matched, so an UPDATE that wrote identical
     * values would also report 0 rows. canTransitionTo() never allows moving to the
     * same status, so that can't happen.
     *
     * @throws InvalidOrderTransition the state machine doesn't allow the move
     * @throws OrderTransitionConflict another request changed the status first
     */
    public function transitionTo(OrderStatus $to): void
    {
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidOrderTransition($this->id, $from, $to);
        }

        $now = $this->freshTimestamp();
        $recall = $from === OrderStatus::Ready && $to === OrderStatus::Preparing;

        $changes = ['status' => $to, 'updated_at' => $now] + match (true) {
            $recall => ['ready_at' => null],  // started_at stays: when it was first started
            $to === OrderStatus::Paid => ['paid_at' => $now],
            $to === OrderStatus::Preparing => ['started_at' => $now],
            $to === OrderStatus::Ready => ['ready_at' => $now],
            $to === OrderStatus::PickedUp => ['picked_up_at' => $now],
            $to === OrderStatus::Cancelled => ['cancelled_at' => $now],
            default => [],
        };

        $query = static::query()->whereKey($this->id)->where('status', $from);

        // Recall increments in SQL, not from this model's count: the model may be stale
        // (ready -> recalled -> ready again since it was loaded) and still match.
        $changed = $recall
            ? $query->increment('recalled_count', 1, $changes)
            : $query->update($changes);

        if ($changed === 0) {
            throw new OrderTransitionConflict($this->id, $from, $to);
        }

        $this->forceFill($changes);

        $synced = array_keys($changes);

        if ($recall) {
            $this->recalled_count = static::query()->whereKey($this->id)->sole(['recalled_count'])->recalled_count;
            $synced[] = 'recalled_count';
        }

        // Only the columns written here: anything else the caller changed stays dirty.
        $this->syncOriginalAttributes($synced);

        // Never throws: the event is ShouldRescue, so a broadcast failure is only reported,
        // and its listeners (SendOrderReadyNotification) catch and report their own failures.
        OrderStatusChanged::dispatch($this->id, $to);
    }

    /**
     * The receipt on APP_URL, for links that leave the current request: emails and Stripe's
     * return URLs. Not route()'s absolute URL, which takes its host from the current request
     * (a barista's Bump, a forged Host header). Redirects within the customer's own request
     * can keep using route().
     *
     * @param  array<string, string>  $query
     */
    public function receiptUrl(array $query = []): string
    {
        $url = rtrim(config()->string('app.url'), '/').route('orders.show', $this, absolute: false);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * Barista board timer: past this the order is late (yellow).
     */
    public function lateAt(): ?CarbonInterface
    {
        return $this->pickup_at;
    }

    /**
     * Barista board timer: past this the order is very late (red), half the longest
     * drink's prep time after the pickup time.
     */
    public function overdueAt(): ?CarbonInterface
    {
        return $this->pickup_at?->copy()->addSeconds($this->prep_minutes * 30);
    }
}
