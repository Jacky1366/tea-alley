<?php

use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Models\MenuItem;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * #67: every place that reads or writes a drink photo uses one disk, MENU_PHOTO_DISK:
 * "public" locally, the object-storage bucket ("photos" here) on Laravel Cloud.
 */
const BUCKET_URL = 'https://photos.example.test';

function usePhotoBucket(): void
{
    Storage::fake('photos', ['url' => BUCKET_URL]);
    config(['tea-alley.menu_photo_disk' => 'photos']);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('locally, photos stay on the public disk', function () {
    expect(config('tea-alley.menu_photo_disk'))->toBe('public')
        ->and(MenuItem::photoDisk())->toBe('public');
});

test('an /admin upload lands on the photo disk under a random name, and nothing goes to the public disk', function () {
    usePhotoBucket();
    Storage::fake('public');
    $this->actingAs(userWithRole('manager'));
    $drink = MenuItem::factory()->create();

    Livewire::test(EditMenuItem::class, ['record' => $drink->getRouteKey()])
        ->fillForm(['photo_path' => UploadedFile::fake()->image('my-tea.png', 400, 400)])
        ->call('save')
        ->assertHasNoFormErrors();

    $path = $drink->fresh()->photo_path;

    expect($path)->toMatch('#^menu-items/[A-Za-z0-9]{40}\.png$#')
        ->and(Storage::disk('photos')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('upload validation is unchanged on the bucket: JPEG / PNG / WebP up to 2 MB', function (UploadedFile $file) {
    usePhotoBucket();
    $this->actingAs(userWithRole('manager'));
    $drink = MenuItem::factory()->create();

    Livewire::test(EditMenuItem::class, ['record' => $drink->getRouteKey()])
        ->fillForm(['photo_path' => $file])
        ->call('save')
        ->assertHasFormErrors(['photo_path']);

    expect($drink->fresh()->photo_path)->toBeNull();
})->with([
    'a PDF' => fn () => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'),
    'a GIF' => fn () => UploadedFile::fake()->image('tea.gif', 400, 400),
    'over 2 MB' => fn () => UploadedFile::fake()->image('big.png', 400, 400)->size(2049),
]);

test('the upload field uses the photo disk; per-file visibility only on a local disk', function () {
    usePhotoBucket();
    $this->actingAs(userWithRole('manager'));

    // A fake disk is local, like the public disk: files are marked public.
    Livewire::test(CreateMenuItem::class)->assertFormFieldExists(
        'photo_path',
        fn (FileUpload $field): bool => $field->getDiskName() === 'photos' && $field->getVisibility() === 'public',
    );

    // Cloud's bucket (an s3 disk): no per-file visibility, as its docs ask.
    config(['filesystems.disks.photos.driver' => 's3']);

    Livewire::test(CreateMenuItem::class)->assertFormFieldExists(
        'photo_path',
        fn (FileUpload $field): bool => $field->getDiskName() === 'photos' && $field->getVisibility() === 'private',
    );
});

test('the /admin table, the menu and the drink page show the photo from the photo disk', function () {
    usePhotoBucket();
    Storage::disk('photos')->put('menu-items/abc.webp', 'image');
    $drink = MenuItem::factory()->create(['photo_path' => 'menu-items/abc.webp']);
    $url = BUCKET_URL.'/menu-items/abc.webp';

    expect($drink->photoUrl())->toBe($url);

    $this->actingAs(userWithRole('manager'));
    Livewire::test(ListMenuItems::class)->assertSee($url, escape: false);

    $this->get('/')->assertOk()->assertSee('src="'.$url.'"', escape: false);
    $this->get(route('menu.show', $drink))->assertOk()->assertSee('src="'.$url.'"', escape: false);
});

test('on a bucket the /admin table doesn\'t check each file exists (a network call per row)', function () {
    usePhotoBucket();
    config(['filesystems.disks.photos.driver' => 's3']);
    MenuItem::factory()->create(['photo_path' => 'menu-items/not-on-disk.webp']);

    $this->actingAs(userWithRole('manager'));
    Livewire::test(ListMenuItems::class)->assertSee(BUCKET_URL.'/menu-items/not-on-disk.webp', escape: false);
});

test('without a photo there is no URL', function () {
    expect(MenuItem::factory()->make()->photoUrl())->toBeNull();
});
