<?php

use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Models\MenuItem;
use App\Providers\AppServiceProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;

/*
 * #69: on Laravel Cloud the only bucket is always the default disk. Livewire's temporary
 * uploads must not follow it there: they stay on the app's local disk.
 */
function bucketIsTheDefaultDisk(): void
{
    // What Cloud's bootstrapper does with an attached bucket, before the app's providers boot.
    config([
        'filesystems.disks.photos' => [
            'driver' => 's3',
            'key' => 'test',
            'secret' => 'test',
            'region' => 'auto',
            'bucket' => 'photos',
            'endpoint' => 'https://r2.example.test',
        ],
        'filesystems.default' => 'photos',
        'livewire.temporary_file_upload.disk' => null,
    ]);

    (new AppServiceProvider(app()))->boot();
}

test('with a bucket as the default disk, temporary uploads stay on the local disk', function () {
    bucketIsTheDefaultDisk();

    // FileUploadConfiguration::disk() always answers "tmp-for-tests" under test, so this reads
    // the setting it uses outside tests; isUsingS3() looks at that real disk.
    expect(config('filesystems.default'))->toBe('photos')
        ->and(config('livewire.temporary_file_upload.disk'))->toBe('local')
        ->and(FileUploadConfiguration::isUsingS3())->toBeFalse();
});

test('an /admin photo upload still saves to the photo disk when the bucket is the default', function () {
    bucketIsTheDefaultDisk();
    Storage::fake('photos');
    config(['tea-alley.menu_photo_disk' => 'photos']);
    $this->seed(RoleSeeder::class);
    $this->actingAs(userWithRole('manager'));
    $drink = MenuItem::factory()->create();

    Livewire::test(EditMenuItem::class, ['record' => $drink->getRouteKey()])
        ->fillForm(['photo_path' => UploadedFile::fake()->image('tea.png', 400, 400)])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Storage::disk('photos')->exists($drink->fresh()->photo_path))->toBeTrue();
});
