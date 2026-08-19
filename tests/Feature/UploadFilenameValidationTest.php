<?php

/**
 * The upload form processes files in afterStateUpdated() and is never
 * form-validated, so the FileUpload ->regex() never runs. save() must be the
 * authoritative gate that rejects anything whose name is not a valid sos report
 * (must start with "sosreport-", optionally prefixed by "secured-") before the
 * file ever touches the vault. Regression for a non-sosreport upload reaching
 * production (e.g. "nvrdiag-1782402766.tar.gz").
 */

use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/** Invoke the private uploadFilenamePattern() helper on a mounted instance. */
function uploadPatternMatches(object $instance, string $filename): bool
{
    $method = (new ReflectionClass($instance))->getMethod('uploadFilenamePattern');
    $method->setAccessible(true);

    return (bool) preg_match($method->invoke($instance), $filename);
}

it('accepts valid sos report filenames', function (string $filename) {
    $instance = Livewire::test('upload', ['vid' => 1])->instance();

    expect(uploadPatternMatches($instance, $filename))->toBeTrue();
})->with([
    'sosreport-host-01-2025-06-01-abcdef.tar.xz',
    'sosreport-host-01-2025-06-01-abcdef.tar.gz',
    'sosreport-host-01-2025-06-01-abcdef.tar.gz.gpg',
    'sosreport-host-01-2025-06-01-abcdef.tar.xz.gpg',
    'secured-sosreport-host-01-2025-06-01-abcdef.tar.gz',
    'sosreport-host.tgz',
]);

it('rejects filenames that are not sos reports', function (string $filename) {
    $instance = Livewire::test('upload', ['vid' => 1])->instance();

    expect(uploadPatternMatches($instance, $filename))->toBeFalse();
})->with([
    'nvrdiag-1782402766.tar.gz',          // the production incident
    'report-host-2025.tar.gz',
    'sosreport.tar.gz',                    // missing the "sosreport-" prefix
    'mysosreport-host.tar.gz',            // prefix must be at the start
    'sosreport-host.zip',                 // unsupported extension
    'secured-host.tar.gz',                // "secured-" without "sosreport-"
    // Command-injection / path-traversal names: the accepted name is stored on
    // disk and interpolated into the gpg/tar unpack commands, so the middle
    // segment must never carry shell metacharacters, spaces or slashes.
    'sosreport-$(id).tar.gz',             // command substitution
    'sosreport-;reboot.tar.gz',           // command separator
    'sosreport-`whoami`.tar.xz',          // backticks
    'sosreport-a|b.tgz',                  // pipe
    'sosreport-x/../../etc.tar',          // slash / path traversal
    'sosreport- spaced .tar.gz',          // space
]);

it('cancels the upload and surfaces an error for a non-sosreport name', function () {
    $instance = Livewire::test('upload', ['vid' => 1])->instance();

    $file = UploadedFile::fake()->create('nvrdiag-1782402766.tar.gz', 10);

    $result = $instance->save($file);

    expect($result)->toBeNull();
    expect($instance->getErrorBag()->has('data.sosreport'))->toBeTrue();
});

it('renders the upload page without crashing when the user has no vault', function () {
    // The user has no vault, so the page mount() hits the "couldn't find your
    // vault" branch. It must surface a notification, not call the undefined
    // errorState() on the page component (regression).
    Volt::test('upload')->assertOk();
});
