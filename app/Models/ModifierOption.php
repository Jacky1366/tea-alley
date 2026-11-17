<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use App\Models\Contracts\Auditable;
use App\Observers\AuditObserver;
use Database\Factories\ModifierOptionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([AuditObserver::class])]
class ModifierOption extends Model implements Auditable
{
    /** @use HasFactory<ModifierOptionFactory> */
    use HasFactory, HasLocalizedName;

    protected $fillable = [
        'modifier_group_id',
        'name_en',
        'name_zh',
        'price_delta_cents',
        'is_default',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'is_default' => 'boolean',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ModifierGroup, $this>
     */
    public function modifierGroup(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class);
    }

    /**
     * "Toppings › Pearls". Uses the group if it's loaded, otherwise asks for its name
     * directly (strict mode forbids lazy loading).
     */
    public function auditLabel(): string
    {
        $group = $this->relationLoaded('modifierGroup')
            ? $this->modifierGroup?->name_en
            : ModifierGroup::query()->whereKey($this->modifier_group_id)->value('name_en');

        return is_string($group) ? $group.' › '.$this->name_en : $this->name_en;
    }
}
