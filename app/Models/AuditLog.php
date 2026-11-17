<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One update or delete of an audited model (T2-8). Written only by AuditObserver.
 *
 * Append-only: an entry can't be updated or deleted through Eloquent, so nothing in the
 * app (including Filament) can rewrite history.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $event
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string $auditable_label
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property CarbonImmutable $created_at
 */
class AuditLog extends Model
{
    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'auditable_label',
        'old_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'auditable_id' => 'integer',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
