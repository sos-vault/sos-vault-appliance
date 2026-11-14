<?php

use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StandaloneDocsSeeder;
use Illuminate\Support\Facades\File;
use Wave\Category;
use Wave\Post;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    User::factory()->create();
});

$expectedSlugs = [
    'standalone-minimum-requirements',
    'standalone-installation-guide',
    'standalone-administration-guide',
    'standalone-user-guide',
    'standalone-quick-start',
    'standalone-faq',
    'standalone-troubleshooting',
    'standalone-architecture',
    'standalone-open-core',
];

it('creates the standalone category', function () {
    $this->seed(StandaloneDocsSeeder::class);

    $cat = Category::where('slug', 'standalone')->first();

    expect($cat)->not->toBeNull();
    expect($cat->name)->toBe('Standalone Docs');
});

it('creates all nine docs posts under the standalone category', function () use ($expectedSlugs) {
    $this->seed(StandaloneDocsSeeder::class);

    $category = Category::where('slug', 'standalone')->firstOrFail();
    $posts = Post::where('category_id', $category->id)->get();

    expect($posts)->toHaveCount(9);
    expect($posts->pluck('slug')->sort()->values()->all())
        ->toBe(collect($expectedSlugs)->sort()->values()->all());
});

it('publishes posts with non-empty bodies', function () {
    $this->seed(StandaloneDocsSeeder::class);

    $posts = Post::whereHas('category', fn ($q) => $q->where('slug', 'standalone'))->get();

    $posts->each(function (Post $post) {
        expect($post->status)->toBe('PUBLISHED');
        expect(strlen($post->body))->toBeGreaterThan(200);
        expect($post->title)->not->toBeEmpty();
    });
});

it('is idempotent on second run', function () {
    $this->seed(StandaloneDocsSeeder::class);
    $this->seed(StandaloneDocsSeeder::class);

    expect(Category::where('slug', 'standalone')->count())->toBe(1);
    expect(Post::whereHas('category', fn ($q) => $q->where('slug', 'standalone'))->count())->toBe(9);
});

it('shows standalone posts in the public blog index query', function () {
    $this->seed(StandaloneDocsSeeder::class);

    // Mirror the query used in resources/themes/anchor/pages/blog/index.blade.php.
    $publicPosts = Post::orderBy('created_at', 'ASC')->get();

    expect($publicPosts->pluck('slug'))->toContain('standalone-installation-guide');
});

it('shows the standalone category in the public nav query', function () {
    $this->seed(StandaloneDocsSeeder::class);

    $navCategories = Category::all();

    expect($navCategories->pluck('slug'))->toContain('standalone');
});

it('publishes the release signing key (public part only) in public-docs for the mirror', function () {
    $key = (string) file_get_contents(base_path('public-docs/sos-vault-signing-key.asc'));

    expect($key)->toContain('-----BEGIN PGP PUBLIC KEY BLOCK-----')
        ->not->toContain('PRIVATE KEY');

    $home = sys_get_temp_dir().'/sos-vault-release-key-'.getmypid();
    @mkdir($home, 0700, true);
    $listing = (string) shell_exec('gpg --homedir '.escapeshellarg($home).' --batch --with-colons --show-keys '.escapeshellarg(base_path('public-docs/sos-vault-signing-key.asc')).' 2>/dev/null');
    exec('gpgconf --homedir '.escapeshellarg($home).' --kill gpg-agent 2>/dev/null');
    File::deleteDirectory($home);

    expect($listing)->toMatch('/^fpr:+2AD06804EA2D2396197179AC6336C307D7212C5F:/m');
});

it('documents verifying the signed SHA256SUMS in both install guides', function () {
    $this->seed(StandaloneDocsSeeder::class);

    $guide = Post::where('slug', 'standalone-installation-guide')->value('body');
    $publicInstall = (string) file_get_contents(base_path('public-docs/docs/INSTALL.md'));

    foreach ([$guide, $publicInstall] as $doc) {
        expect($doc)
            ->toContain('https://sos-vault.com/downloads/SHA256SUMS.asc')
            // Key comes from GitHub, never from the download server it vouches for.
            ->toContain('https://raw.githubusercontent.com/sos-vault/sos-vault-appliance/main/sos-vault-signing-key.asc')
            ->not->toContain('sos-vault.com/downloads/sos-vault-signing-key.asc')
            ->toContain('gpg --verify SHA256SUMS.asc SHA256SUMS')
            ->toContain('sha256sum --check --ignore-missing SHA256SUMS')
            ->toMatch('/2AD0 6804 EA2D 2396 1971 +79AC 6336 C307 D721 2C5F/');
    }
});
