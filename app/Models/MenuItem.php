<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Closure;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory, HasLocalizedName;

    public const TAX_STANDARD = 'standard';

    public const TAX_CARBONATED = 'carbonated';

    protected $fillable = [
        'category_id',
        'name_en',
        'name_zh',
        'description',
        'price_cents',
        'photo_path',
        'tax_category',
        'prep_minutes',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'prep_minutes' => 'integer',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Everything the drink builder and ModifierValidator need, in display order:
     * groups by pivot sort_order then group sort_order, options by sort_order.
     * Use with with() or loadMissing() so nothing is lazy loaded.
     *
     * @return array<array-key, Closure|string> a plain relation name, or relation name => constraint
     */
    public static function builderRelations(): array
    {
        return [
            'category',
            'modifierGroups' => fn (BelongsToMany $query) => $query
                ->orderBy('modifier_groups.sort_order')
                ->orderBy('modifier_groups.id'),
            'modifierGroups.options' => fn (HasMany $query) => $query
                ->orderBy('sort_order')
                ->orderBy('id'),
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsToMany<ModifierGroup, $this>
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
