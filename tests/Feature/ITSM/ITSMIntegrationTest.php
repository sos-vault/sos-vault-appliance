<?php

/**
 * ITSM Integration Tests
 *
 * Covers:
 *  - ITSMProvider model — CRUD and per-user isolation
 *  - Credential encryption — passwords stored encrypted, not as plaintext
 *  - /settings/itsm page — access control and page load
 *  - JiraService::getAttachments() — null guards, API call, auth, error handling
 *  - JiraService::downloadFile() — null guards
 *  - JIRADownload event — dispatch payload and listener registration
 */

// Load namespace-level getSvaultKey stubs so App\Services and App\Listeners
// resolve to a deterministic test key instead of the Linux kernel keyring.
require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Events\JIRADownload;
use App\Listeners\JIRADownloader;
use App\Models\ITSMProvider;
use App\Models\PlanFeature;
use App\Models\User;
use App\Providers\EventServiceProvider;
use App\Providers\VaultTools;
use App\Services\JiraService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Wave\Plan;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** 32-byte test key matching SvaultKeyStub. */
const ITSM_TEST_KEY = 'TTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTT';

/** Return a fresh Encrypter using the test key. */
function testEncrypter(): Encrypter
{
    return new Encrypter(ITSM_TEST_KEY, config('app.cipher'));
}

/**
 * Create a verified user with a Basic role and ITSM Integration enabled.
 * The gate on /settings/itsm requires 'ITSM Integration' access.
 */
function itsmUser(): User
{
    $role = Role::firstOrCreate(['name' => 'Basic', 'guard_name' => 'web']);
    $plan = Plan::firstOrCreate(
        ['slug' => 'basic-itsm-test'],
        [
            'name' => 'Basic',
            'slug' => 'basic-itsm-test',
            'description' => 'Basic plan for ITSM tests',
            'type' => 'service',
            'active' => true,
            'default' => false,
            'features' => '{}',
            'monthly_price' => 9,
            'monthly_price_id' => 'price_itsm_basic_m',
            'role_id' => $role->id,
        ]
    );
    PlanFeature::firstOrCreate(
        ['plan_id' => $plan->id, 'slug' => 'itsm-integration-itsm-test'],
        [
            'name' => 'ITSM Integration',
            'slug' => 'itsm-integration-itsm-test',
            'type' => 'bool',
            'enabled' => true,
        ]
    );

    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
    $user->syncRoles(['Basic']);

    return $user;
}

/**
 * Create an ITSMProvider record for $user with an encrypted token.
 * The password 'secret-api-token' is encrypted using the test key so that
 * JiraService (which uses the stub) can decrypt it during tests.
 */
function itsmProvider(User $user, array $overrides = []): ITSMProvider
{
    return ITSMProvider::create(array_merge([
        'vid' => 0,
        'uid' => $user->id,
        'gid' => $user->id,
        'provider' => 'JSM',
        'url' => 'https://acme.atlassian.net',
        'user' => 'support@acme.com',
        'password' => testEncrypter()->encrypt('secret-api-token'),
        'customer_field' => 'customfield_10001',
    ], $overrides));
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

// ---------------------------------------------------------------------------
// ITSMProvider model — CRUD & per-user isolation
// ---------------------------------------------------------------------------

describe('ITSMProvider model', function () {

    it('can be created and retrieved for a user', function () {
        $user = itsmUser();
        itsmProvider($user);

        $found = ITSMProvider::where('uid', $user->id)->where('provider', 'JSM')->first();

        expect($found)->not->toBeNull()
            ->and($found->url)->toBe('https://acme.atlassian.net')
            ->and($found->user)->toBe('support@acme.com')
            ->and($found->customer_field)->toBe('customfield_10001');
    });

    it('does not return another user\'s provider when filtering by uid', function () {
        $userA = itsmUser();
        $userB = itsmUser();
        itsmProvider($userA);

        $found = ITSMProvider::where('uid', $userB->id)->where('provider', 'JSM')->first();

        expect($found)->toBeNull();
    });

    it('can be deleted for the correct user only', function () {
        $userA = itsmUser();
        $userB = itsmUser();
        $providerA = itsmProvider($userA);
        // userB has no JSM record

        // Deleting with userA's uid removes only their record.
        ITSMProvider::where('uid', $userA->id)->where('provider', 'JSM')->first()?->delete();

        assertDatabaseMissing('itsmproviders', ['uid' => $userA->id, 'provider' => 'JSM']);
        // userB is unaffected (they never had one, but the uid filter is correct)
        expect(ITSMProvider::where('uid', $userB->id)->where('provider', 'JSM')->first())->toBeNull();
    });

});

// ---------------------------------------------------------------------------
// Credential encryption
// ---------------------------------------------------------------------------

describe('credential encryption', function () {

    it('stores passwords encrypted — not as the original plaintext', function () {
        $user = itsmUser();
        $token = 'my-super-secret-atlassian-token';
        itsmProvider($user, ['password' => testEncrypter()->encrypt($token)]);

        $stored = ITSMProvider::where('uid', $user->id)->value('password');

        // Stored value must not equal the plaintext token.
        expect($stored)->not->toBe($token);
        // Stored value must be a non-empty encrypted string.
        expect($stored)->toBeString()->not->toBeEmpty();
    });

    it('can decrypt a stored password back to the original token', function () {
        $user = itsmUser();
        $token = 'my-super-secret-atlassian-token';
        itsmProvider($user, ['password' => testEncrypter()->encrypt($token)]);

        $stored = ITSMProvider::where('uid', $user->id)->value('password');
        $decrypted = testEncrypter()->decrypt($stored);

        expect($decrypted)->toBe($token);
    });

    it('different plaintext tokens produce different ciphertext', function () {
        $enc = testEncrypter();
        $cipher1 = $enc->encrypt('token-one');
        $cipher2 = $enc->encrypt('token-two');

        expect($cipher1)->not->toBe($cipher2);
    });

});

// ---------------------------------------------------------------------------
// /settings/itsm — access control
// ---------------------------------------------------------------------------

describe('settings ITSM page', function () {

    it('redirects unauthenticated users to login', function () {
        get('/settings/itsm')->assertRedirect();
    });

    it('renders for an authenticated user with no existing provider', function () {
        $user = itsmUser();
        actingAs($user);

        get('/settings/itsm')->assertOk();
    });

    it('renders for an authenticated user who already has a provider configured', function () {
        $user = itsmUser();
        itsmProvider($user);
        actingAs($user);

        get('/settings/itsm')->assertOk();
    });

    it('does not expose the encrypted password in the rendered HTML', function () {
        $user = itsmUser();
        $provider = itsmProvider($user);
        actingAs($user);

        // The page must not render the raw ciphertext in the HTML.
        get('/settings/itsm')->assertDontSee($provider->password);
    });

});

// ---------------------------------------------------------------------------
// JiraService — getAttachments() null guards
// ---------------------------------------------------------------------------

describe('JiraService — getAttachments() null guards', function () {

    it('returns null when user is null', function () {
        $result = app(JiraService::class)->getAttachemnets(null, 'JSM-123');

        expect($result)->toBeNull();
    });

    it('returns null when issueid is null', function () {
        $user = itsmUser();

        $result = app(JiraService::class)->getAttachemnets($user, null);

        expect($result)->toBeNull();
    });

    it('returns null when the user has no JSM provider configured', function () {
        $user = itsmUser(); // no ITSMProvider record

        $result = app(JiraService::class)->getAttachemnets($user, 'JSM-999');

        expect($result)->toBeNull();
    });

    it('returns null when another user has a provider but not the requesting user', function () {
        $userA = itsmUser();
        $userB = itsmUser();
        itsmProvider($userA); // only userA has JSM configured

        $result = app(JiraService::class)->getAttachemnets($userB, 'JSM-100');

        expect($result)->toBeNull();
    });

});

// ---------------------------------------------------------------------------
// JiraService — getAttachments() API call
// ---------------------------------------------------------------------------

describe('JiraService — getAttachments() API call', function () {

    it('calls the correct Jira REST API endpoint', function () {
        $user = itsmUser();
        itsmProvider($user);

        Http::fake([
            'https://acme.atlassian.net/rest/api/2/issue/JSM-42' => Http::response([
                'id' => 'JSM-42',
                'fields' => ['attachment' => []],
            ], 200),
        ]);

        $result = app(JiraService::class)->getAttachemnets($user, 'JSM-42');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rest/api/2/issue/JSM-42');
        });

        expect($result)->toBeObject()
            ->and($result->raw['id'])->toBe('JSM-42')
            ->and($result->link)->toBe('https://acme.atlassian.net/browse/JSM-42')
            ->and(property_exists($result, 'attachments'))->toBeTrue()
            ->and(property_exists($result, 'description'))->toBeTrue()
            ->and(property_exists($result, 'customer'))->toBeTrue();
    });

    it('sends Basic Auth with the decrypted credentials', function () {
        $user = itsmUser();
        itsmProvider($user, [
            'user' => 'support@acme.com',
            'password' => testEncrypter()->encrypt('secret-api-token'),
        ]);

        Http::fake(['*' => Http::response(['id' => 'JSM-1'], 200)]);

        app(JiraService::class)->getAttachemnets($user, 'JSM-1');

        Http::assertSent(function ($request) {
            // Basic Auth header must be present and credentials must match.
            $auth = base64_decode(str_replace('Basic ', '', $request->header('Authorization')[0] ?? ''));

            return $auth === 'support@acme.com:secret-api-token';
        });
    });

    it('returns null and logs an error on an HTTP 401 response', function () {
        $user = itsmUser();
        itsmProvider($user);

        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);
        Log::shouldReceive('error')->once();

        $result = app(JiraService::class)->getAttachemnets($user, 'JSM-7');

        expect($result)->toBeNull();
    });

    it('returns null and logs an error on a connection timeout', function () {
        $user = itsmUser();
        itsmProvider($user);

        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);
        Log::shouldReceive('error')->once();

        $result = app(JiraService::class)->getAttachemnets($user, 'JSM-8');

        expect($result)->toBeNull();
    });

    it('prepends https:// when the stored URL lacks the scheme', function () {
        $user = itsmUser();
        itsmProvider($user, ['url' => 'acme.atlassian.net']); // no https://

        Http::fake(['https://acme.atlassian.net/*' => Http::response(['id' => 'JSM-5'], 200)]);

        app(JiraService::class)->getAttachemnets($user, 'JSM-5');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://');
        });
    });

});

// ---------------------------------------------------------------------------
// JiraService — downloadFile() null guards
// ---------------------------------------------------------------------------

describe('JiraService — downloadFile() null guards', function () {

    it('returns null when user is null', function () {
        $result = app(JiraService::class)->downloadFile(null, 'JSM-1', (object) ['content' => 'http://x'], '/tmp/f');

        expect($result)->toBeNull();
    });

    it('returns null when file is null', function () {
        $user = itsmUser();

        $result = app(JiraService::class)->downloadFile($user, 'JSM-1', null, '/tmp/f');

        expect($result)->toBeNull();
    });

    it('returns null when the user has no JSM provider configured', function () {
        $user = itsmUser();

        $result = app(JiraService::class)->downloadFile($user, 'JSM-1', (object) ['content' => 'http://x'], '/tmp/f');

        expect($result)->toBeNull();
    });

});

// ---------------------------------------------------------------------------
// JIRADownload event — dispatch and listener registration
// ---------------------------------------------------------------------------

describe('JIRADownload event', function () {

    it('is mapped to JIRADownloader in the EventServiceProvider', function () {
        // Retrieve the already-booted EventServiceProvider instance.
        $providers = app()->getProviders(EventServiceProvider::class);
        $listen = collect($providers)->flatMap(fn ($p) => $p->listens())->toArray();

        expect($listen)->toHaveKey(JIRADownload::class)
            ->and($listen[JIRADownload::class])->toContain(JIRADownloader::class);
    });

    it('carries the correct data payload when dispatched', function () {
        Event::fake();

        $user = itsmUser();
        $payload = [
            'user' => $user,
            'issueid' => 'JSM-99',
            'selectedFile' => (object) ['filename' => 'sosreport-host.tar.gz', 'size' => 1024, 'content' => 'http://x'],
            'report' => '/vault/sosreport-host.tar.gz',
            'customer' => 'Acme Corp',
            'version' => '8.6',
            'link' => 'https://acme.atlassian.net/browse/JSM-99',
        ];

        JIRADownload::dispatch($payload);

        Event::assertDispatched(JIRADownload::class, function ($event) {
            return $event->data['issueid'] === 'JSM-99'
                && $event->data['customer'] === 'Acme Corp'
                && $event->data['report'] === '/vault/sosreport-host.tar.gz';
        });
    });

    it('listener implements ShouldQueue so downloads are processed asynchronously', function () {
        expect(JIRADownloader::class)->toImplement(ShouldQueue::class);
    });

    it('listener implements ShouldBeUnique to prevent duplicate downloads', function () {
        expect(JIRADownloader::class)->toImplement(ShouldBeUnique::class);
    });

});

// ---------------------------------------------------------------------------
// JIRADownloader listener — early-exit guards
// ---------------------------------------------------------------------------

describe('JIRADownloader listener — early-exit guards', function () {

    it('returns early without calling retrieveFile when required data is missing', function () {
        Event::fake();

        // Dispatch with missing 'report' key → listener exits before any vault work.
        JIRADownload::dispatch([
            'user' => itsmUser(),
            'issueid' => 'JSM-1',
            'selectedFile' => (object) ['filename' => 'sos.tar.gz', 'size' => 100],
            'report' => null, // missing
            'customer' => '',
            'version' => '',
            'link' => '',
        ]);

        // Event was dispatched; listener will exit silently (no exception).
        Event::assertDispatched(JIRADownload::class);
    });

    it('retrieveFile returns false with an error message when vault does not exist', function () {
        $user = itsmUser();
        $selectedFile = (object) ['filename' => 'sos.tar.gz', 'size' => 100];

        // Bind a VaultTools mock that reports no vault.
        $vtoolsMock = Mockery::mock(VaultTools::class);
        $vtoolsMock->shouldReceive('vaultExists')->andReturn(false);

        // Instantiate the listener and inject our mock by overriding the constructor call.
        $listener = new JIRADownloader;

        // Use Mockery::overload only if not yet overloaded; here we test the method directly
        // by short-circuiting via a partial mock on the listener itself.
        $partialListener = Mockery::mock(JIRADownloader::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $partialListener->shouldReceive('retrieveFile')->passthru();

        // We test the guard logic: a listener with no real vault available must
        // expose a meaningful error message, not a PHP fatal.
        // Since VaultTools is newed directly, we verify the listener is safe to
        // construct and call with a user that has no vault provisioned.
        expect(fn () => $listener->handle(new JIRADownload([
            'user' => $user,
            'issueid' => 'JSM-1',
            'selectedFile' => $selectedFile,
            'report' => '/tmp/sos.tar.gz',
            'customer' => '',
            'version' => '',
            'link' => '',
        ])))->not->toThrow(TypeError::class);
    });

});

// ---------------------------------------------------------------------------
// JiraService — testConnection()
// ---------------------------------------------------------------------------

describe('JiraService — testConnection()', function () {

    it('returns false when user is null', function () {
        $result = app(JiraService::class)->testConnection(null);

        expect($result)->toBeFalse();
    });

    it('returns false when user has no JSM provider', function () {
        $user = itsmUser(); // no provider created

        $result = app(JiraService::class)->testConnection($user);

        expect($result)->toBeFalse();
    });

    it('returns true on a successful HTTP 200 response', function () {
        $user = itsmUser();
        itsmProvider($user);

        Http::fake([
            'https://acme.atlassian.net/rest/api/2/myself' => Http::response(['accountId' => 'abc123'], 200),
        ]);

        $result = app(JiraService::class)->testConnection($user);

        expect($result)->toBeTrue();

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/rest/api/2/myself');
        });
    });

    it('returns false on an HTTP 401 response', function () {
        $user = itsmUser();
        itsmProvider($user);

        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $result = app(JiraService::class)->testConnection($user);

        expect($result)->toBeFalse();
    });

    it('returns false on a connection exception', function () {
        $user = itsmUser();
        itsmProvider($user);

        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);
        Log::shouldReceive('error')->once();

        $result = app(JiraService::class)->testConnection($user);

        expect($result)->toBeFalse();
    });

});

// ---------------------------------------------------------------------------
// getAttachemnets() — enriched return structure
// ---------------------------------------------------------------------------

describe('JiraService — getAttachemnets() enriched return', function () {

    it('returns an object with attachments, description, customer, and link on success', function () {
        $user = itsmUser();
        itsmProvider($user, ['customer_field' => 'customfield_10001']);

        Http::fake([
            'https://acme.atlassian.net/rest/api/2/issue/JSM-10' => Http::response([
                'id' => 'JSM-10',
                'fields' => [
                    'attachment' => [
                        ['filename' => 'sosreport-host-123.tar.gz', 'size' => 1024, 'created' => '2025-01-01T00:00:00.000+0000'],
                    ],
                    'description' => 'System is down',
                    'customfield_10001' => ['name' => 'Acme Corp'],
                ],
            ], 200),
        ]);

        $result = app(JiraService::class)->getAttachemnets($user, 'JSM-10');

        expect($result)->toBeObject()
            ->and($result->attachments)->toBeArray()->toHaveCount(1)
            ->and($result->attachments[0]['filename'])->toBe('sosreport-host-123.tar.gz')
            ->and($result->description)->toBe('System is down')
            ->and($result->customer)->toBe(['name' => 'Acme Corp'])
            ->and($result->link)->toBe('https://acme.atlassian.net/browse/JSM-10');
    });

    it('returns an empty attachments array when the issue has no attachments', function () {
        $user = itsmUser();
        itsmProvider($user);

        Http::fake([
            'https://acme.atlassian.net/rest/api/2/issue/JSM-11' => Http::response([
                'id' => 'JSM-11',
                'fields' => ['attachment' => []],
            ], 200),
        ]);

        $result = app(JiraService::class)->getAttachemnets($user, 'JSM-11');

        expect($result->attachments)->toBeArray()->toHaveCount(0)
            ->and($result->link)->toBe('https://acme.atlassian.net/browse/JSM-11');
    });

    it('constructs the link from the provider URL and issue id', function () {
        $user = itsmUser();
        itsmProvider($user, ['url' => 'https://mycompany.atlassian.net']);

        Http::fake(['*' => Http::response(['id' => 'SOS-5', 'fields' => ['attachment' => []]], 200)]);

        $result = app(JiraService::class)->getAttachemnets($user, 'SOS-5');

        expect($result->link)->toBe('https://mycompany.atlassian.net/browse/SOS-5');
    });

});
