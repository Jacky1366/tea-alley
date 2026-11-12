<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Categories\Tables\CategoriesTable;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'name_en';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    /**
     * menu_items.category_id is restrictOnDelete, so deleting a category that still has drinks
     * would throw. Cancel the action with a friendly notification instead.
     *
     * @param  Collection<int, Category>  $categories
     */
    public static function cancelDeleteIfCategoriesHaveDrinks(Action $action, Collection $categories): void
    {
        $blocked = $categories->filter(fn (Category $category): bool => $category->menuItems()->exists());

        if ($blocked->isEmpty()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Category still has drinks')
            ->body('Move or delete the drinks in '.$blocked->pluck('name_en')->join(', ', ' and ').' first.')
            ->persistent()
            ->send();

        $action->cancel();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
