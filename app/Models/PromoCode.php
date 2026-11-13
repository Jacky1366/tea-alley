<?php

namespace App\Models;

use App\Enums\PromoType;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'expires_at',
        'max_uses',
        'used_count',
        'is_active',
    ];

    /**
     * Mirrors the database defaults (see Order::$attributes).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'used_count' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => PromoType::class,
            'value' => 'integer',
            'expires_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
