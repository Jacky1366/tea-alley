<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * #64: the 30 drink descriptions in English and Chinese, and a back-fill for an existing menu.
 *
 *     php artisan db:seed --class=MenuDescriptionsSeeder
 *
 * - Fresh installs get the descriptions from MenuSeeder, which passes them when it creates
 *   each drink (and then calls this seeder, which finds nothing left to fill).
 * - On an existing menu, each column is filled only when it is null or blank: text written in
 *   /admin is never overwritten. Running it again changes nothing.
 * - Drinks are matched by category slug + name_en. A drink renamed or moved in /admin isn't
 *   found and is skipped (reported).
 * - Saves go through Eloquent, so the audit log records each filled drink, with no user: the
 *   system made the change (docs/decisions.md).
 */
class MenuDescriptionsSeeder extends Seeder
{
    /**
     * Category slug => name_en => [description_en, description_zh]. Copied from issue #64.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const DESCRIPTIONS = [
        'milk-tea' => [
            'Classic Milk Tea' => [
                'Our house black tea, brewed strong and shaken with creamy milk. The one that started it all.',
                '招牌紅茶濃濃沖泡，加入香醇奶香搖勻，最經典的一杯。',
            ],
            'Brown Sugar Pearl Milk Tea' => [
                'Chewy pearls simmered in brown sugar syrup, streaked down the glass and topped with milk tea.',
                '黑糖慢熬的Q彈珍珠，糖漿沿杯壁流下，再倒入香濃奶茶。',
            ],
            'Taro Milk Tea' => [
                'Smooth taro blended into milk tea for a gentle, nutty sweetness.',
                '綿密芋頭融入奶茶，帶出淡淡的芋香與甜味。',
            ],
            'Jasmine Milk Green Tea' => [
                'Fragrant jasmine green tea with milk. Lighter and more floral than classic milk tea.',
                '茉莉綠茶加上奶香，比經典奶茶更清爽、帶花香。',
            ],
            'Hojicha Milk Tea' => [
                'Roasted Japanese green tea with milk. Toasty, smooth and low in bitterness.',
                '日式焙茶加奶，焙火香氣溫潤，幾乎不苦澀。',
            ],
        ],
        'fruit-tea' => [
            'Passion Fruit Green Tea' => [
                'Green tea with real passion fruit pulp and seeds. Tangy, bright and refreshing.',
                '綠茶加入百香果果肉和籽，酸甜清爽。',
            ],
            'Mango Green Tea' => [
                'Green tea with ripe mango and mango chunks. Sweet, juicy and summery.',
                '熟芒果搭配綠茶，還有芒果果肉，香甜多汁。',
            ],
            'Grapefruit Jasmine Tea' => [
                'Jasmine green tea with ruby grapefruit pulp. Lightly bitter, floral and crisp.',
                '茉莉綠茶加入紅葡萄柚果肉，微苦回甘、清香爽口。',
            ],
            'Kumquat Lemon Green Tea' => [
                'Green tea with freshly squeezed kumquat and lemon. Sour, zesty and a Taiwanese classic.',
                '現擠金桔和檸檬加綠茶，酸香夠味的台灣經典。',
            ],
            'Mixed Fruit Tea' => [
                'Black tea loaded with diced apple, orange, pineapple and passion fruit.',
                '紅茶裡滿滿蘋果、柳橙、鳳梨和百香果，一杯喝到好幾種水果。',
            ],
        ],
        'fresh-milk' => [
            'Brown Sugar Fresh Milk' => [
                'Fresh milk with house-cooked brown sugar syrup streaked down the glass. No tea.',
                '鮮奶搭配自熬黑糖糖漿，虎紋掛杯，不含茶。',
            ],
            'Matcha Fresh Milk' => [
                'Whisked matcha layered over fresh milk. Stir before you sip.',
                '現刷抹茶疊在鮮奶上，喝之前記得攪拌。',
            ],
            'Black Tea Fresh Milk' => [
                'Our house black tea poured over fresh milk. Richer and creamier than milk tea.',
                '招牌紅茶倒在鮮奶上，比奶茶更濃郁滑順。',
            ],
            'Taro Purée Fresh Milk' => [
                'Hand-mashed taro purée with fresh milk. Thick and rich, with real bits of taro.',
                '手工芋泥搭配鮮奶，綿密濃厚，喝得到芋頭顆粒。',
            ],
            'Tieguanyin Fresh Milk' => [
                'Roasted Tieguanyin oolong over fresh milk. Deep, toasty and smooth.',
                '炭焙鐵觀音疊在鮮奶上，焙香濃郁、口感滑順。',
            ],
        ],
        'sparkling' => [
            'Lemon Sparkling Green Tea' => [
                'Green tea with sparkling water and fresh lemon. Crisp and fizzy.',
                '綠茶加氣泡水和新鮮檸檬，清爽帶勁。',
            ],
            'Peach Oolong Sparkling Tea' => [
                'Oolong tea with peach and sparkling water. Fruity, floral and light.',
                '烏龍茶加蜜桃和氣泡水，果香清新。',
            ],
            'Lychee Sparkling Tea' => [
                'Light tea with lychee and sparkling water, plus lychee pieces.',
                '清茶加荔枝和氣泡水，還有荔枝果粒。',
            ],
            'Kyoho Grape Sparkling Tea' => [
                'Kyoho grape with sparkling water and tea. Deep, juicy and fizzy.',
                '巨峰葡萄加氣泡水和茶，果香濃郁。',
            ],
            'Green Plum Sparkling Tea' => [
                'Green plum with sparkling water and tea, plus plum pieces. Sweet, sour and refreshing.',
                '青梅搭配氣泡水和茶，還有青梅果粒，酸甜開胃。',
            ],
        ],
        'pure-tea' => [
            'Jasmine Green Tea' => [
                'Green tea scented with jasmine flowers. Light and floral.',
                '茉莉花薰香的綠茶，清香淡雅。',
            ],
            'Roasted Oolong Tea' => [
                'Charcoal-roasted oolong with a deep, toasty finish.',
                '炭焙烏龍，焙火香濃，尾韻甘醇。',
            ],
            'Assam Black Tea' => [
                'Bold Assam black tea with a malty finish.',
                '阿薩姆紅茶，茶味濃郁，帶麥芽香。',
            ],
            'Four Seasons Oolong Tea' => [
                'Taiwanese Four Seasons Spring oolong. Fresh, floral and light.',
                '台灣四季春青茶，清新花香，入口清爽。',
            ],
            'Jin Xuan Oolong Tea' => [
                'Taiwanese Jin Xuan oolong, known for its natural milky aroma.',
                '台灣金萱烏龍，帶有天然的淡淡奶香。',
            ],
        ],
        'seasonal' => [
            'Strawberry Fresh Milk' => [
                'Strawberry purée under fresh milk. Only while strawberries are in season.',
                '新鮮草莓果泥加鮮奶，草莓季限定。',
            ],
            'Osmanthus Oolong Milk Tea' => [
                'Oolong milk tea finished with sweet osmanthus flowers.',
                '烏龍奶茶點綴桂花，香氣甜美。',
            ],
            'Winter Melon Lemonade' => [
                'House-cooked winter melon syrup with fresh lemon. Sweet, tangy and caffeine-free.',
                '自熬冬瓜糖加新鮮檸檬，酸甜解渴，不含咖啡因。',
            ],
            'Black Sesame Milk Tea' => [
                'Toasted black sesame blended into milk tea. Nutty and rich.',
                '焙炒黑芝麻融入奶茶，芝麻香濃。',
            ],
            'Yuzu Green Tea' => [
                'Green tea with yuzu and thin strips of peel. Bright, citrusy and a little bitter.',
                '綠茶搭配柚子與果皮，柑橘香氣清新，微苦回甘。',
            ],
        ],
    ];

    public function run(): void
    {
        $categories = Category::query()->whereIn('slug', array_keys(self::DESCRIPTIONS))->get()->keyBy('slug');

        $items = MenuItem::query()
            ->whereIn('category_id', $categories->modelKeys())
            ->get()
            ->keyBy(fn (MenuItem $item): string => $item->category_id.'|'.$item->name_en);

        $filled = 0;
        $missing = [];

        foreach (self::DESCRIPTIONS as $slug => $drinks) {
            foreach ($drinks as $name => [$en, $zh]) {
                $category = $categories->get($slug);
                $item = $category === null ? null : $items->get($category->id.'|'.$name);

                if ($item === null) {
                    $missing[] = $name;

                    continue;
                }

                if (self::isBlank($item->description_en)) {
                    $item->description_en = $en;
                }

                if (self::isBlank($item->description_zh)) {
                    $item->description_zh = $zh;
                }

                if ($item->isDirty()) {
                    $item->save();
                    $filled++;
                }
            }
        }

        $this->command?->info("Descriptions filled for {$filled} drinks.");

        if ($missing !== []) {
            $this->command?->warn('Not found (renamed, moved, or deleted), skipped: '.implode(', ', $missing));
        }
    }

    /**
     * The seeded pair for a drink, or [null, null] for a drink with no seeded description.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function for(string $slug, string $name): array
    {
        return self::DESCRIPTIONS[$slug][$name] ?? [null, null];
    }

    private static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
