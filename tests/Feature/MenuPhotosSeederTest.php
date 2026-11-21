<?php

use App\Models\AuditLog;
use App\Models\MenuItem;
use Database\Seeders\MenuPhotosSeeder;
use Database\Seeders\MenuSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * #67: the 30 drink photos live in the repo, and MenuPhotosSeeder attaches them to drinks
 * that have no photo, on the photo disk.
 */
beforeEach(function () {
    Storage::fake('public');
    $this->seed(MenuSeeder::class);
});

function seedPhotos(): void
{
    test()->artisan('db:seed', ['--class' => MenuPhotosSeeder::class])->assertSuccessful();
}

test('the repo has one 700x875 WebP per seeded drink, named by slug, under 1.2 MB in total', function () {
    $photos = MenuPhotosSeeder::photos();
    $slugs = MenuItem::query()->pluck('name_en')->map(fn (string $name): string => Str::slug($name))->sort()->values()->all();

    expect(array_keys($photos))->toBe($slugs)->toHaveCount(30);

    $total = 0;
    foreach ($photos as $path) {
        [$width, $height, $type] = getimagesize($path);
        expect([$width, $height, $type])->toBe([700, 875, IMAGETYPE_WEBP], basename($path));
        $total += filesize($path);
    }

    expect($total)->toBeLessThan(1_200_000);
});

test('every drink gets its own photo, under a random name in menu-items/, with the repo file\'s bytes', function () {
    seedPhotos();

    foreach (MenuItem::query()->get() as $item) {
        expect($item->photo_path)->toMatch('#^menu-items/[A-Za-z0-9]{40}\.webp$#', $item->name_en)
            ->and(Storage::disk('public')->get($item->photo_path))
            ->toBe(file_get_contents(MenuPhotosSeeder::PHOTO_DIRECTORY.'/'.Str::slug($item->name_en).'.webp'));
    }

    expect(Storage::disk('public')->allFiles())->toHaveCount(30);
});

test('running it again changes nothing', function () {
    seedPhotos();
    $paths = MenuItem::query()->orderBy('id')->pluck('photo_path')->all();

    test()->artisan('db:seed', ['--class' => MenuPhotosSeeder::class])
        ->expectsOutputToContain('Photos attached: 0. Already had a photo, kept: 30.')
        ->assertSuccessful();

    expect(MenuItem::query()->orderBy('id')->pluck('photo_path')->all())->toBe($paths)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(30);
});

test('a photo set in /admin is never replaced', function () {
    $drink = MenuItem::query()->where('name_en', 'Taro Milk Tea')->firstOrFail();
    $drink->update(['photo_path' => 'menu-items/from-admin.png']);

    seedPhotos();

    expect($drink->fresh()->photo_path)->toBe('menu-items/from-admin.png')
        ->and(MenuItem::query()->whereNull('photo_path')->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(29);
});

test('a drink renamed in /admin is skipped and its photo listed', function () {
    $drink = MenuItem::query()->where('name_en', 'Taro Milk Tea')->firstOrFail();
    $drink->update(['name_en' => 'Taro Dream']);

    test()->artisan('db:seed', ['--class' => MenuPhotosSeeder::class])
        ->expectsOutputToContain('Photos attached: 29.')
        ->expectsOutputToContain('No drink found (renamed or deleted), skipped: taro-milk-tea.webp')
        ->assertSuccessful();

    expect($drink->fresh()->photo_path)->toBeNull();
});

test('photos go to the configured photo disk, not the public disk', function () {
    Storage::fake('photos');
    config(['tea-alley.menu_photo_disk' => 'photos']);

    seedPhotos();

    expect(Storage::disk('photos')->allFiles())->toHaveCount(30)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('each attached photo is in the audit log with no user: the system made the change', function () {
    seedPhotos();

    expect(AuditLog::query()->count())->toBe(30)
        ->and(AuditLog::query()->whereNotNull('user_id')->count())->toBe(0)
        ->and(AuditLog::query()->pluck('new_values')->every(fn (array $values): bool => array_keys($values) === ['photo_path']))->toBeTrue();
});

test('a fresh local seed doesn\'t attach photos (DatabaseSeeder doesn\'t call this seeder)', function () {
    expect(MenuItem::query()->whereNotNull('photo_path')->count())->toBe(0)
        ->and(file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('MenuPhotosSeeder');
});
