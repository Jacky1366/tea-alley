<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed the drink menu: 6 categories of 5 drinks, and the modifier groups and options.
 *
 * Safe to run again on a database that already has the menu (#61):
 *
 *     php artisan db:seed --class=MenuSeeder
 *
 * - Every row is found or created (firstOrCreate), never updated: categories by `slug`,
 *   modifier groups by `name_en`, options by (group, `name_en`), drinks by (category,
 *   `name_en`). Prices, names, availability, sort order and photos edited in /admin are
 *   left as they are.
 * - Modifier groups are attached only to drinks this run created, so a drink whose groups
 *   were changed in /admin keeps them.
 * - Something that no longer matches its key comes back as a new row: a seeded drink,
 *   option or group that was deleted, a drink that was renamed (by `name_en`) or moved to
 *   another category, a group that was renamed, a category whose slug changed.
 * - Descriptions (#64): new drinks are created with them; then MenuDescriptionsSeeder fills
 *   only descriptions that are still empty. It never overwrites text written in /admin.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $groups = $this->seedModifierGroups();
            $this->seedDrinks($groups);
        });

        $this->call(MenuDescriptionsSeeder::class);
    }

    /**
     * @param  array<string, ModifierGroup>  $groups
     */
    private function seedDrinks(array $groups): void
    {

        $categories = [
            ['slug' => 'milk-tea', 'name_en' => 'Milk Tea', 'name_zh' => '奶茶', 'drinks' => [
                ['Classic Milk Tea', '經典奶茶', 595, 'milk' => true],
                ['Brown Sugar Pearl Milk Tea', '黑糖珍珠奶茶', 695, 'milk' => true],
                ['Taro Milk Tea', '芋頭奶茶', 645, 'milk' => true],
                ['Jasmine Milk Green Tea', '茉莉奶綠', 595, 'milk' => true],
                ['Hojicha Milk Tea', '焙茶奶茶', 645, 'milk' => true],
            ]],
            ['slug' => 'fruit-tea', 'name_en' => 'Fruit Tea', 'name_zh' => '果茶', 'drinks' => [
                ['Passion Fruit Green Tea', '百香果綠茶', 595],
                ['Mango Green Tea', '芒果綠茶', 625],
                ['Grapefruit Jasmine Tea', '葡萄柚茉莉綠茶', 650],
                ['Kumquat Lemon Green Tea', '金桔檸檬綠茶', 575],
                ['Mixed Fruit Tea', '綜合水果茶', 695],
            ]],
            ['slug' => 'fresh-milk', 'name_en' => 'Fresh Milk', 'name_zh' => '鮮奶', 'drinks' => [
                ['Brown Sugar Fresh Milk', '黑糖鮮奶', 675, 'milk' => true],
                ['Matcha Fresh Milk', '抹茶鮮奶', 695, 'milk' => true],
                ['Black Tea Fresh Milk', '紅茶鮮奶', 625, 'milk' => true],
                ['Taro Purée Fresh Milk', '芋泥鮮奶', 725, 'milk' => true],
                ['Tieguanyin Fresh Milk', '鐵觀音鮮奶', 650, 'milk' => true],
            ]],
            ['slug' => 'sparkling', 'name_en' => 'Sparkling', 'name_zh' => '氣泡茶', 'drinks' => [
                ['Lemon Sparkling Green Tea', '檸檬氣泡綠茶', 625],
                ['Peach Oolong Sparkling Tea', '蜜桃烏龍氣泡茶', 650],
                ['Lychee Sparkling Tea', '荔枝氣泡茶', 650],
                ['Kyoho Grape Sparkling Tea', '巨峰葡萄氣泡茶', 675],
                ['Green Plum Sparkling Tea', '青梅氣泡茶', 625],
            ]],
            ['slug' => 'pure-tea', 'name_en' => 'Pure Tea', 'name_zh' => '純茶', 'drinks' => [
                ['Jasmine Green Tea', '茉莉綠茶', 450],
                ['Roasted Oolong Tea', '炭焙烏龍茶', 475],
                ['Assam Black Tea', '阿薩姆紅茶', 450],
                ['Four Seasons Oolong Tea', '四季春青茶', 450],
                ['Jin Xuan Oolong Tea', '金萱烏龍茶', 475],
            ]],
            ['slug' => 'seasonal', 'name_en' => 'Seasonal', 'name_zh' => '季節限定', 'drinks' => [
                ['Strawberry Fresh Milk', '草莓鮮奶', 750, 'milk' => true],
                ['Osmanthus Oolong Milk Tea', '桂花烏龍奶茶', 695, 'milk' => true],
                ['Winter Melon Lemonade', '冬瓜檸檬', 575],
                ['Black Sesame Milk Tea', '黑芝麻奶茶', 725, 'milk' => true],
                ['Yuzu Green Tea', '柚子綠茶', 650],
            ]],
        ];

        foreach ($categories as $categoryIndex => $data) {
            $category = Category::firstOrCreate(['slug' => $data['slug']], [
                'name_en' => $data['name_en'],
                'name_zh' => $data['name_zh'],
                'sort_order' => $categoryIndex + 1,
                'is_active' => true,
            ]);

            $isSparkling = $data['slug'] === 'sparkling';

            foreach ($data['drinks'] as $drinkIndex => $drink) {
                [$descriptionEn, $descriptionZh] = MenuDescriptionsSeeder::for($data['slug'], $drink[0]);

                $item = $category->menuItems()->firstOrCreate(['name_en' => $drink[0]], [
                    'name_zh' => $drink[1],
                    'description_en' => $descriptionEn,
                    'description_zh' => $descriptionZh,
                    'price_cents' => $drink[2],
                    'tax_category' => $isSparkling ? MenuItem::TAX_CARBONATED : MenuItem::TAX_STANDARD,
                    'prep_minutes' => 3,
                    'is_available' => true,
                    'sort_order' => $drinkIndex + 1,
                ]);

                if (! $item->wasRecentlyCreated) {
                    continue;
                }

                // Everyone gets Size / Sugar / Ice; toppings for everything except sparkling;
                // milk substitutes only for milk-based drinks.
                $attach = ['size', 'sugar', 'ice'];

                if (! $isSparkling) {
                    $attach[] = 'toppings';
                }

                if ($drink['milk'] ?? false) {
                    $attach[] = 'milk';
                }

                $item->modifierGroups()->attach(
                    collect($attach)->mapWithKeys(fn (string $key, int $i) => [
                        $groups[$key]->id => ['sort_order' => $i + 1],
                    ])->all(),
                );
            }
        }
    }

    /**
     * @return array<string, ModifierGroup>
     */
    private function seedModifierGroups(): array
    {
        $definitions = [
            'size' => [['Size', '容量', true, 1, 1], [
                ['Medium', '中杯', 0, true],
                ['Large', '大杯', 50],
            ]],
            'sugar' => [['Sugar', '甜度', true, 1, 1], [
                ['0% Sugar', '無糖', 0],
                ['25% Sugar', '微糖', 0],
                ['50% Sugar', '半糖', 0],
                ['75% Sugar', '少糖', 0],
                ['100% Sugar', '全糖', 0, true],
            ]],
            'ice' => [['Ice', '冰塊', true, 1, 1], [
                ['No Ice', '去冰', 0],
                ['Light Ice', '微冰', 0],
                ['Less Ice', '少冰', 0],
                ['Regular Ice', '正常冰', 0, true],
                ['Extra Ice', '多冰', 0],
            ]],
            'toppings' => [['Toppings', '加料', false, 0, 3], [
                ['Pearls', '珍珠', 75],
                ['Coconut Jelly', '椰果', 75],
                ['Pudding', '布丁', 100],
                ['Grass Jelly', '仙草', 75],
            ]],
            'milk' => [['Milk', '換奶', false, 0, 1], [
                ['Oat Milk', '燕麥奶', 60],
                ['Lactose-free Milk', '無乳糖牛奶', 50],
            ]],
        ];

        $groups = [];
        $groupIndex = 0;

        foreach ($definitions as $key => [[$nameEn, $nameZh, $required, $min, $max], $options]) {
            $group = ModifierGroup::firstOrCreate(['name_en' => $nameEn], [
                'name_zh' => $nameZh,
                'is_required' => $required,
                'min_select' => $min,
                'max_select' => $max,
                'sort_order' => ++$groupIndex,
            ]);

            foreach ($options as $optionIndex => $option) {
                $group->options()->firstOrCreate(['name_en' => $option[0]], [
                    'name_zh' => $option[1],
                    'price_delta_cents' => $option[2],
                    'is_default' => $option[3] ?? false,
                    'is_available' => true,
                    'sort_order' => $optionIndex + 1,
                ]);
            }

            $groups[$key] = $group;
        }

        return $groups;
    }
}
