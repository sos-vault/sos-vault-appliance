<?php

/**
 * Email Management Tests
 *
 * Covers:
 *  - Non-admin cannot access the Manage Emails page
 *  - Admin can access the Manage Emails page
 *  - Saving master template persists to the settings table
 *  - Compose email dispatches SendUserEmail event with correct payload
 *  - Compose email does not dispatch when required fields are missing
 *  - From dropdown only contains @sos-vault.com addresses
 *  - ManageSettings::sendTestEmail dispatches SendUserEmail (not Mail::html)
 *  - SendEmailListener sends without BCC when not provided
 */

use App\Events\SendUserEmail;
use App\Filament\Pages\ManageEmails;
use App\Filament\Pages\ManageSettings;
use App\Listeners\SendEmailListener;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Wave\Setting;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

uses(RefreshDatabase::class)->in(__FILE__);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::forget('wave_settings');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeAdminEmailUser(): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
    $user->syncRoles(['admin']);

    return $user;
}

function makeRegularEmailUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
}

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('redirects unauthenticated users from the manage emails page', function () {
    get('/admin/manage-emails')->assertRedirect();
});

it('blocks non-admin users from the manage emails page', function () {
    $user = makeRegularEmailUser();
    actingAs($user)->get('/admin/manage-emails')->assertStatus(403);
});

it('allows admin users to access the manage emails page', function () {
    $admin = makeAdminEmailUser();
    actingAs($admin)->get('/admin/manage-emails')->assertOk();
});

// ---------------------------------------------------------------------------
// Template Manager — saveTemplate
// ---------------------------------------------------------------------------

it('saveTemplate persists the master template to the settings table', function () {
    $admin = makeAdminEmailUser();
    $template = '<html><body>{{ $title }}{!! $body !!}</body></html>';

    actingAs($admin);
    $page = new ManageEmails;
    $page->data = ['master_template' => $template];
    $page->saveTemplate();

    assertDatabaseHas('settings', [
        'key' => 'email_master_template',
        'value' => $template,
    ]);
});

it('saveTemplate updates an existing setting without creating a duplicate', function () {
    $admin = makeAdminEmailUser();
    actingAs($admin);

    Setting::create([
        'key' => 'email_master_template',
        'display_name' => 'Email Master Template',
        'value' => 'old template',
        'type' => 'text',
        'order' => 0,
    ]);

    $page = new ManageEmails;
    $page->data = ['master_template' => 'new template'];
    $page->saveTemplate();

    expect(Setting::where('key', 'email_master_template')->count())->toBe(1);
    expect(Setting::where('key', 'email_master_template')->value('value'))->toBe('new template');
});

// ---------------------------------------------------------------------------
// Compose Email — sendEmail
// ---------------------------------------------------------------------------

it('sendEmail dispatches SendUserEmail with the correct payload', function () {
    Event::fake([SendUserEmail::class]);

    $admin = makeAdminEmailUser();
    actingAs($admin);

    $page = new ManageEmails;
    $page->data = [
        'title'           => 'Hello',
        'subject'         => 'Test Subject',
        'to'              => 'user@example.com',
        'cc'              => 'cc@example.com',
        'from'            => 'support',
        'body'            => '<p>Body content</p>',
        'attachments'     => [],
        'master_template' => '',
    ];
    $page->sendEmail();

    Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $event): bool {
        return $event->data['to']      === 'user@example.com'
            && $event->data['from']    === 'support@sos-vault.com'
            && $event->data['subject'] === 'Test Subject'
            && $event->data['title']   === 'Hello'
            && $event->data['cc']      === ['cc@example.com']
            && $event->data['type']    === 'response';
    });
});

it('sendEmail does not dispatch event when required fields are missing', function () {
    Event::fake([SendUserEmail::class]);

    $admin = makeAdminEmailUser();
    actingAs($admin);

    $page = new ManageEmails;
    $page->data = [
        'title' => '',
        'subject' => 'Test',
        'to' => 'user@example.com',
        'from' => 'support',
        'body' => '',
    ];
    $page->sendEmail();

    Event::assertNotDispatched(SendUserEmail::class);
});

// ---------------------------------------------------------------------------
// From-address validation
// ---------------------------------------------------------------------------

it('fromOptions only contains sos-vault.com addresses', function () {
    $options = ManageEmails::fromOptions();

    expect($options)->not->toBeEmpty();

    foreach ($options as $value) {
        expect($value)->toEndWith('@sos-vault.com');
    }
});

it('fromOptions contains exactly the 10 expected prefixes', function () {
    $expected = ['support', 'jlrueda', 'tanderson', 'linuxjedi', 'admin', 'sales', 'ceo', 'privacy', 'policy', 'contact'];
    $options = ManageEmails::fromOptions();

    expect(array_keys($options))->toBe($expected);
});

// ---------------------------------------------------------------------------
// ManageSettings::sendTestEmail — branded template
// ---------------------------------------------------------------------------

it('sendTestEmail dispatches SendUserEmail with branded template type', function () {
    Event::fake([SendUserEmail::class]);
    Mail::fake();

    $admin = makeAdminEmailUser();
    actingAs($admin);

    $page = new ManageSettings;
    $page->sendTestEmail('dest@example.com', 'Test Subject', '<p>Hello</p>');

    Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $event): bool {
        return $event->data['to'] === 'dest@example.com'
            && $event->data['subject'] === 'Test Subject'
            && $event->data['type'] === 'response'
            && $event->data['from'] === 'support@sos-vault.com';
    });

    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------------
// SendEmailListener — BCC support
// ---------------------------------------------------------------------------

it('SendEmailListener handles events without BCC without throwing', function () {
    Mail::fake();

    $event = new SendUserEmail([
        'type' => 'response',
        'to' => 'to@example.com',
        'from' => 'support@sos-vault.com',
        'subject' => 'No BCC',
        'title' => 'Test',
        'body' => 'Hello',
    ]);

    $listener = new SendEmailListener;

    expect(fn () => $listener->handle($event))->not->toThrow(Throwable::class);
});
