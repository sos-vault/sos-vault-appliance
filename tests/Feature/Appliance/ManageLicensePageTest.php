<?php

/**
 * Manage License page (appliance admin) — section layout.
 *
 * The page stacks three form sections in a fixed order:
 *   1. "Request a License"  (Generate button)
 *   2. the generated key    (visible only after Generate runs)
 *   3. "Install License"    (upload the .lic)
 * This pins that order — the key must render BELOW "Request a License" and
 * ABOVE "Install License" — and that the key section is hidden until a key
 * has been generated.
 */

use App\Filament\Pages\ManageLicense;
use App\Models\User;
use App\Services\LicenseRequestService;
use Database\Seeders\RolesTableSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);

    $this->admin = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
    ]);
    $this->admin->syncRoles(['admin']);

    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
});

function sampleLicenseKey(): string
{
    $payload = json_encode(['v' => 1, 'tokens' => ['sha256:'.str_repeat('a', 64)]]);

    return 'SOSV1.'.rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
}

it('hides the generated-key section until a key is generated', function () {
    Livewire::test(ManageLicense::class)
        ->assertSet('licenseKey', null)
        ->assertSee(__('licensing.request.section_heading'))
        ->assertSee(__('appliance.manage_license.install_section_heading'))
        ->assertDontSee(__('licensing.request.key_heading'));
});

it('shows the key between "Request a License" and "Install License" after generating', function () {
    $key = sampleLicenseKey();

    $this->mock(LicenseRequestService::class, function ($mock) use ($key) {
        $mock->shouldReceive('generate')->once()->andReturn($key);
    });

    Livewire::test(ManageLicense::class)
        ->call('requestLicense')
        ->assertSet('licenseKey', $key)
        ->assertSeeHtml($key)
        ->assertSeeTextInOrder([
            __('licensing.request.section_heading'),
            __('licensing.request.key_heading'),
            __('appliance.manage_license.install_section_heading'),
        ]);
});

it('deletes the uploaded .lic from the temp disk after install', function () {
    // Livewire uploads land on the `vault` temp disk (config/livewire.php) at
    // /vault/wkng. Fake it so the test never touches the real /vault.
    Storage::fake('vault');

    Livewire::test(ManageLicense::class)
        ->set('data.lic_file', UploadedFile::fake()->createWithContent('license.lic', 'not-a-valid-signed-license'))
        ->call('installLicense');

    // installLicense() reads the bytes then deletes the temp upload BEFORE
    // attempting verification, so the file is gone whether or not the signature
    // check passes (here it fails — the point is no .lic is left behind).
    expect(Storage::disk('vault')->allFiles())->toBe([]);
});

it('does not restrict the .lic upload by MIME type', function () {
    $page = Livewire::test(ManageLicense::class)->instance();

    $field = $page->form->getFlatFields(withHidden: true)['lic_file'] ?? null;

    expect($field)->not->toBeNull();
    // A non-empty acceptedFileTypes would map to a FilePond/HTML accept filter
    // that rejects .lic (empty browser MIME) as "File of invalid type".
    expect($field->getAcceptedFileTypes())->toBeEmpty();
});
