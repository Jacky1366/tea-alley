<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * #67: the 30 drink photos travel with the code, so any fresh environment (Laravel Cloud, a
 * rebuild) gets the full menu without uploads.
 *
 *     php artisan db:seed --class=MenuPhotosSeeder
 *
 * - The photos are in database/seeders/menu-photos/, named Str::slug(name_en).webp.
 * - A photo is attached only to a drink with no photo: one set in /admin is never replaced.
 *   Running it again changes nothing.
 * - Stored on the photo disk (MENU_PHOTO_DISK) under menu-items/ with a random name, like an
 *   /admin upload.
 * - A photo whose drink isn't found (renamed or deleted in /admin) is skipped and listed.
 * - Saves go through Eloquent, so the audit log records each photo, with no user: the system
 *   made the change (like MenuDescriptionsSeeder).
 * - Not run by DatabaseSeeder: a local `migrate:fresh --seed` keeps today's placeholders and
 *   writes no files.
 */
class MenuPhotosSeeder extends Seeder
{
    public const PHOTO_DIRECTORY = __DIR__.'/menu-photos';

    public function run(): void
    {
        $items = MenuItem::query()->get()->keyBy(fn (MenuItem $item): string => Str::slug($item->name_en));
        $disk = Storage::disk(MenuItem::photoDisk());

        $attached = 0;
        $kept = 0;
        $unmatched = [];

        foreach (self::photos() as $slug => $path) {
            $item = $items->get($slug);

            if ($item === null) {
                $unmatched[] = basename($path);

                continue;
            }

            if ($item->photo_path !== null) {
                $kept++;

                continue;
            }

            $stored = $disk->putFile('menu-items', new File($path));

            if ($stored === false) {
                $this->command?->error("Couldn't store ".basename($path).' on the "'.MenuItem::photoDisk().'" disk.');

                continue;
            }

            $item->photo_path = $stored;
            $item->save();
            $attached++;
        }

        $this->command?->info("Photos attached: {$attached}. Already had a photo, kept: {$kept}.");

        if ($unmatched !== []) {
            $this->command?->warn('No drink found (renamed or deleted), skipped: '.implode(', ', $unmatched));
        }
    }

    /**
     * The repo's photos, slug => absolute path, in name order.
     *
     * @return array<string, string>
     */
    public static function photos(): array
    {
        $photos = [];

        foreach (glob(self::PHOTO_DIRECTORY.'/*.webp') ?: [] as $path) {
            $photos[basename($path, '.webp')] = $path;
        }

        ksort($photos);

        return $photos;
    }
}
