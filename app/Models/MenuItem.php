<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedDescription;
use App\Models\Concerns\HasLocalizedName;
use App\Models\Contracts\Auditable;
use App\Observers\AuditObserver;
use Closure;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[ObservedBy([AuditObserver::class])]
class MenuItem extends Model implements Auditable
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory, HasLocalizedDescription, HasLocalizedName;

    public const TAX_STANDARD = 'standard';

    public const TAX_CARBONATED = 'carbonated';

    protected $fillable = [
        'category_id',
        'name_en',
        'name_zh',
        'description_en',
        'description_zh',
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

    /**
     * The order lines that snapshot this drink. For sales counts only (T2-9): never read a
     * line's name or price from the drink, the line has its own snapshot.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function auditLabel(): string
    {
        return $this->name_en;
    }

    /**
     * #67: the disk drink photos live on (MENU_PHOTO_DISK): "public" locally, the object-storage
     * bucket on Laravel Cloud. Everything that reads or writes a photo uses this.
     */
    public static function photoDisk(): string
    {
        return config()->string('tea-alley.menu_photo_disk');
    }

    /** The photo's public URL, or null without a photo. */
    public function photoUrl(): ?string
    {
        return $this->photo_path === null ? null : Storage::disk(self::photoDisk())->url($this->photo_path);
    }
}
