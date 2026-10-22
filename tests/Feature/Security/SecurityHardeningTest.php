<?php

use App\Events\SendUserEmail;
use App\Listeners\SendEmailListener;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Gate;

// ---------------------------------------------------------------------------
// R6 — viewAuthSetup gate is role-based, not a hardcoded email
// ---------------------------------------------------------------------------

it('grants viewAuthSetup to the admin role and denies everyone else', function () {
    $this->seed(RolesTableSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $plain = User::factory()->create();

    expect(Gate::forUser($admin)->allows('viewAuthSetup'))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('viewAuthSetup'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// R7 — admin-authored email body is sanitized before it reaches a recipient
// ---------------------------------------------------------------------------

it('strips script/javascript vectors from the response email body', function () {
    config()->set('mail.default', 'array');
    app('mail.manager')->forgetMailers();

    $event = new SendUserEmail([
        'type' => 'response',
        'to' => 'to@example.com',
        'from' => 'support@sos-vault.com',
        'subject' => 'Subject',
        'title' => 'Title',
        'body' => '<p>Hello there</p><script>alert(1)</script>'
            .'<a href="javascript:alert(2)">click</a>',
    ]);

    (new SendEmailListener)->handle($event);

    $html = app('mail.manager')->mailer('array')->getSymfonyTransport()
        ->messages()->first()->getOriginalMessage()->getHtmlBody();

    expect($html)->toContain('Hello there')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('javascript:');
});

// ---------------------------------------------------------------------------
// R12 — CORS is no longer an open wildcard
// ---------------------------------------------------------------------------

it('does not allow wildcard CORS origins', function () {
    $origins = config('cors.allowed_origins');

    expect($origins)->toBeArray()
        ->and($origins)->not->toContain('*');
});
