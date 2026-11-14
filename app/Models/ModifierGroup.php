<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModifierGroup extends Model
{
    /** @use HasFactory<ModifierGroupFactory> */
    use HasFactory, HasLocalizedName;

    protected $fillable = [
        'name_en',
        'name_zh',
        'is_required',
        'min_select',
        'max_select',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'min_select' => 'integer',
            'max_select' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The fewest options a customer must choose in this group. A required group needs
     * at least one even if min_select is 0. The drink builder (which offers "None" only
     * when this is 0) and ModifierValidator both use this, so they can't disagree.
     */
    public function effectiveMin(): int
    {
        return max($this->min_select, $this->is_required ? 1 : 0);
    }

    /**
     * @return HasMany<ModifierOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class);
    }

    /**
     * @return BelongsToMany<MenuItem, $this>
     */
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class)->withPivot('sort_order');
    }
}
