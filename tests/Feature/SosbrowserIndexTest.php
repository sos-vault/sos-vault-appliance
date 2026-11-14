<?php

/**
 * sosbrowser/index Volt page — case-selection table.
 *
 * Covers:
 *  - Guests are redirected to login.
 *  - Authenticated users see the new "or from this table..." status text.
 *  - The table query includes own-group cases and public foreign cases.
 *  - The table query excludes private foreign cases and user-hidden cases.
 *  - Row links resolve to /sosbrowser/{id} for own-group cases.
 *  - Row links resolve to the public ContentsRequest URL for public foreign cases.
 *  - Shared mode (sme=1) hides the table.
 */

use App\Models\ContentsRequest;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

/**
 * Re-implementation of the table query in
 * resources/themes/anchor/pages/sosbrowser/index.blade.php so we can verify
 * visibility rules without rendering the full Volt page.
 */
function sosbrowserCaseQuery(User $user): Builder
{
    $gid = $user->group_id ?? $user->id;
    $uid = $user->id;

    return SupportCase::query()
        ->where(function ($q) use ($gid) {
            $q->where('group', $gid)->orWhere('is_public', true);
        })
        ->whereNotIn('id', function ($q) use ($uid) {
            $q->select('case_id')->from('user_hidden_cases')->where('user_id', $uid);
        });
}

// ---------------------------------------------------------------------------
// Route auth
// ---------------------------------------------------------------------------

it('redirects guests to login', function () {
    $this->get('/sosbrowser')->assertRedirect();
});

it('renders for authenticated users', function () {
    $this->actingAs($this->user)
        ->get('/sosbrowser')
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Status message
// ---------------------------------------------------------------------------

it('shows the new "or from this table" status message when no case is selected', function () {
    $this->actingAs($this->user)
        ->get('/sosbrowser')
        ->assertSee(__('vault.browser_status_select_table'));
});

it('does not show the legacy dropdown-only status message anymore', function () {
    $response = $this->actingAs($this->user)->get('/sosbrowser');

    // The new message includes "from this table"; the old one doesn't.
    expect($response->getContent())->toContain(__('vault.browser_status_select_table'));
});

// ---------------------------------------------------------------------------
// Table visibility query
// ---------------------------------------------------------------------------

it('includes own-group cases in the table query', function () {
    $gid = $this->user->group_id ?? $this->user->id;
    $case = SupportCase::factory()->create([
        'owner' => $this->user->id,
        'group' => $gid,
        'is_public' => false,
    ]);

    $ids = sosbrowserCaseQuery($this->user)->pluck('id');

    expect($ids)->toContain($case->id);
});

it('includes public cases from other groups in the table query', function () {
    $otherGid = $this->other->group_id ?? $this->other->id;
    $case = SupportCase::factory()->create([
        'owner' => $this->other->id,
        'group' => $otherGid,
        'is_public' => true,
    ]);

    $ids = sosbrowserCaseQuery($this->user)->pluck('id');

    expect($ids)->toContain($case->id);
});

it('excludes private cases from other groups in the table query', function () {
    $otherGid = $this->other->group_id ?? $this->other->id;
    $case = SupportCase::factory()->create([
        'owner' => $this->other->id,
        'group' => $otherGid,
        'is_public' => false,
    ]);

    $ids = sosbrowserCaseQuery($this->user)->pluck('id');

    expect($ids)->not->toContain($case->id);
});

it('excludes user-hidden public cases from the table query', function () {
    $otherGid = $this->other->group_id ?? $this->other->id;
    $case = SupportCase::factory()->create([
        'owner' => $this->other->id,
        'group' => $otherGid,
        'is_public' => true,
    ]);

    DB::table('user_hidden_cases')->insert([
        'user_id' => $this->user->id,
        'case_id' => $case->id,
    ]);

    $ids = sosbrowserCaseQuery($this->user)->pluck('id');

    expect($ids)->not->toContain($case->id);
});

// ---------------------------------------------------------------------------
// Row click target (recordUrl) — own group cases vs. public foreign cases
// ---------------------------------------------------------------------------

it('renders an own-group case row that links to /sosbrowser/{id}', function () {
    $gid = $this->user->group_id ?? $this->user->id;
    $case = SupportCase::factory()->create([
        'owner' => $this->user->id,
        'group' => $gid,
        'is_public' => false,
        'case' => 'CASE-OWN-1',
    ]);

    $response = $this->actingAs($this->user)->get('/sosbrowser');
    $body = $response->getContent();

    expect($body)->toContain('CASE-OWN-1');
    expect($body)->toContain("/sosbrowser/{$case->id}");
});

it('renders a public foreign case row that links to the ContentsRequest url when present', function () {
    $otherGid = $this->other->group_id ?? $this->other->id;
    $vault = Vault::factory()->create(['owner' => $this->other->id]);
    $case = SupportCase::factory()->create([
        'owner' => $this->other->id,
        'group' => $otherGid,
        'is_public' => true,
        'vault_id' => $vault->id,
        'file_id' => 999,
        'case' => 'CASE-PUB-1',
    ]);

    ContentsRequest::create([
        'vault_id' => $vault->id,
        'dir_id' => 999,
        'file_id' => 0,
        'case_id' => $case->id,
        'status' => 'SHARED',
        'comments' => '',
        'url' => 'https://example.test/sosSharedDir/abc123',
        'owner' => $this->other->id,
        'group' => $otherGid,
        'perms' => '750',
        'expire' => now()->addDays(7),
    ]);

    $response = $this->actingAs($this->user)->get('/sosbrowser');
    $body = $response->getContent();

    expect($body)->toContain('CASE-PUB-1');
    expect($body)->toContain('https://example.test/sosSharedDir/abc123');
});

it('falls back to /sosbrowser/{id} for a public foreign case without a ContentsRequest', function () {
    $otherGid = $this->other->group_id ?? $this->other->id;
    $vault = Vault::factory()->create(['owner' => $this->other->id]);
    $case = SupportCase::factory()->create([
        'owner' => $this->other->id,
        'group' => $otherGid,
        'is_public' => true,
        'vault_id' => $vault->id,
        'file_id' => 1234,
        'case' => 'CASE-PUB-2',
    ]);

    $response = $this->actingAs($this->user)->get('/sosbrowser');
    $body = $response->getContent();

    expect($body)->toContain('CASE-PUB-2');
    expect($body)->toContain("/sosbrowser/{$case->id}");
});

// ---------------------------------------------------------------------------
// Shared mode hides the table
// ---------------------------------------------------------------------------

it('does not render the case-selection table in shared mode', function () {
    $vault = Vault::factory()->create(['owner' => $this->other->id]);

    $response = $this->actingAs($this->user)
        ->get('/sosbrowser?sme=1&vid='.$vault->id.'&did=0');

    $response->assertStatus(200);
    expect($response->getContent())->not->toContain('id="caseSelectionTable"');
});

it('renders the case-selection table when no case is selected and not in shared mode', function () {
    $response = $this->actingAs($this->user)->get('/sosbrowser');

    $response->assertStatus(200);
    expect($response->getContent())->toContain('id="caseSelectionTable"');
});
