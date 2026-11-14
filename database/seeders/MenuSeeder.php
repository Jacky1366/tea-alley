<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    /**
     * Seed the drink menu: categories, drinks, and modifier groups/options.
     */
    public function run(): void
    {
        $groups = $this->seedModifierGroups();

        $categories = [
            ['slug' => 'milk-tea', 'name_en' => 'Milk Tea', 'name_zh' => '奶茶', 'drinks' => [
                ['Classic Milk Tea', '經典奶茶', 595, 'milk' => true],
                ['Brown Sugar Pearl Milk Tea', '黑糖珍珠奶茶', 695, 'milk' => true],
                ['Taro Milk Tea', '芋頭奶茶', 645, 'milk' => true],
            ]],
            ['slug' => 'fruit-tea', 'name_en' => 'Fruit Tea', 'name_zh' => '果茶', 'drinks' => [
                ['Passion Fruit Green Tea', '百香果綠茶', 595],
                ['Mango Green Tea', '芒果綠茶', 625],
                ['Grapefruit Jasmine Tea', '葡萄柚茉莉綠茶', 650],
            ]],
            ['slug' => 'fresh-milk', 'name_en' => 'Fresh Milk', 'name_zh' => '鮮奶', 'drinks' => [
                ['Brown Sugar Fresh Milk', '黑糖鮮奶', 675, 'milk' => true],
                ['Matcha Fresh Milk', '抹茶鮮奶', 695, 'milk' => true],
                ['Black Tea Fresh Milk', '紅茶鮮奶', 625, 'milk' => true],
            ]],
            ['slug' => 'sparkling', 'name_en' => 'Sparkling', 'name_zh' => '氣泡茶', 'drinks' => [
                ['Lemon Sparkling Green Tea', '檸檬氣泡綠茶', 625],
                ['Peach Oolong Sparkling Tea', '蜜桃烏龍氣泡茶', 650],
                ['Lychee Sparkling Tea', '荔枝氣泡茶', 650],
            ]],
            ['slug' => 'pure-tea', 'name_en' => 'Pure Tea', 'name_zh' => '純茶', 'drinks' => [
                ['Jasmine Green Tea', '茉莉綠茶', 450],
                ['Roasted Oolong Tea', '炭焙烏龍茶', 475],
                ['Assam Black Tea', '阿薩姆紅茶', 450],
            ]],
            ['slug' => 'seasonal', 'name_en' => 'Seasonal', 'name_zh' => '季節限定', 'drinks' => [
                ['Strawberry Fresh Milk', '草莓鮮奶', 750, 'milk' => true],
                ['Osmanthus Oolong Milk Tea', '桂花烏龍奶茶', 695, 'milk' => true],
                ['Winter Melon Lemonade', '冬瓜檸檬', 575],
            ]],
        ];

        foreach ($categories as $categoryIndex => $data) {
            $category = Category::create([
                'name_en' => $data['name_en'],
                'name_zh' => $data['name_zh'],
                'slug' => $data['slug'],
                'sort_order' => $categoryIndex + 1,
                'is_active' => true,
            ]);

            $isSparkling = $data['slug'] === 'sparkling';

            foreach ($data['drinks'] as $drinkIndex => $drink) {
                $item = $category->menuItems()->create([
                    'name_en' => $drink[0],
                    'name_zh' => $drink[1],
                    'price_cents' => $drink[2],
                    'tax_category' => $isSparkling ? MenuItem::TAX_CARBONATED : MenuItem::TAX_STANDARD,
                    'prep_minutes' => 3,
                    'is_available' => true,
                    'sort_order' => $drinkIndex + 1,
                ]);

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
            $group = ModifierGroup::create([
                'name_en' => $nameEn,
                'name_zh' => $nameZh,
                'is_required' => $required,
                'min_select' => $min,
                'max_select' => $max,
                'sort_order' => ++$groupIndex,
            ]);

            foreach ($options as $optionIndex => $option) {
                $group->options()->create([
                    'name_en' => $option[0],
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
