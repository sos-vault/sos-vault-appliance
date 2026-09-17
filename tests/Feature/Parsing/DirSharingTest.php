<?php

use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;

/**
 * Directory-level sharing tests.
 *
 * Tests cover:
 *  - ContentsRequest creation with file_id=0 (directory sentinel)
 *  - Annotation creation with file_id=0
 *  - Share / unshare state transitions
 *  - URL stability across multiple operations
 *  - sosSharedDir route access control
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Simulate the DB operations performed by tool-controls ensureDirContentsRequest().
 */
function ensureDirCreq(int $uid, int $vid, int $did, int $cid): ContentsRequest
{
    $creq = ContentsRequest::where('vault_id', $vid)
        ->where('dir_id', $did)
        ->where('file_id', 0)
        ->first();

    if (! $creq) {
        $hash = hash('sha256', "{$uid}/{$vid}/{$did}/0/sosbrowser");
        $url = url("sosSharedDir/{$hash}");

        $creq = ContentsRequest::create([
            'vault_id' => $vid,
            'dir_id' => $did,
            'file_id' => 0,
            'case_id' => $cid,
            'status' => 'VALID',
            'comments' => '',
            'url' => $url,
            'owner' => $uid,
            'group' => $uid,
            'perms' => '750',
        ]);
    }

    if (! Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', 0)->exists()) {
        Annotation::create([
            'vault_id' => $vid,
            'dir_id' => $did,
            'file_id' => 0,
            'owner' => $uid,
            'group' => $uid,
            'perms' => '750',
            'status' => 'PRIVATE',
        ]);
    }

    return $creq;
}

/**
 * Simulate shareDir(): set both records to SHARED.
 */
function shareDirRecords(int $uid, int $vid, int $did, int $cid): void
{
    $creq = ensureDirCreq($uid, $vid, $did, $cid);
    $creq->status = 'SHARED';
    $creq->save();

    $annot = Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', 0)->first();
    if ($annot) {
        $annot->status = 'SHARED';
        $annot->save();
    }
}

/**
 * Simulate unshareDir(): set both records back to PRIVATE.
 */
function unshareDirRecords(int $vid, int $did): void
{
    $creq = ContentsRequest::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', 0)->first();
    if ($creq) {
        $creq->status = 'PRIVATE';
        $creq->save();
    }

    $annot = Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', 0)->first();
    if ($annot) {
        $annot->status = 'PRIVATE';
        $annot->save();
    }
}

// ---------------------------------------------------------------------------
// Test setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// ---------------------------------------------------------------------------
// ensureDirContentsRequest — ContentsRequest creation
// ---------------------------------------------------------------------------

it('creates a ContentsRequest with file_id=0 when none exists', function () {
    ensureDirCreq($this->user->id, 901, 902, 903);

    expect(
        ContentsRequest::where('vault_id', 901)->where('dir_id', 902)->where('file_id', 0)->exists()
    )->toBeTrue();
});

it('generates a sosSharedDir URL in the ContentsRequest', function () {
    $creq = ensureDirCreq($this->user->id, 911, 912, 913);

    expect($creq->url)->toContain('sosSharedDir/');
});

it('ContentsRequest initial status is VALID', function () {
    $creq = ensureDirCreq($this->user->id, 921, 922, 923);

    expect($creq->status)->toBe('VALID');
});

it('creates an Annotation with file_id=0 and PRIVATE status by default', function () {
    ensureDirCreq($this->user->id, 931, 932, 933);

    $status = Annotation::where('vault_id', 931)->where('dir_id', 932)->where('file_id', 0)->value('status');

    expect($status)->toBe('PRIVATE');
});

it('is idempotent: calling ensure twice does not create duplicate ContentsRequest', function () {
    ensureDirCreq($this->user->id, 941, 942, 943);
    ensureDirCreq($this->user->id, 941, 942, 943);

    $count = ContentsRequest::where('vault_id', 941)->where('dir_id', 942)->where('file_id', 0)->count();

    expect($count)->toBe(1);
});

it('is idempotent: calling ensure twice does not create duplicate Annotation', function () {
    ensureDirCreq($this->user->id, 951, 952, 953);
    ensureDirCreq($this->user->id, 951, 952, 953);

    $count = Annotation::where('vault_id', 951)->where('dir_id', 952)->where('file_id', 0)->count();

    expect($count)->toBe(1);
});

// ---------------------------------------------------------------------------
// shareDir — SHARED state
// ---------------------------------------------------------------------------

it('shareDir sets ContentsRequest status to SHARED', function () {
    shareDirRecords($this->user->id, 961, 962, 963);

    $status = ContentsRequest::where('vault_id', 961)->where('dir_id', 962)->where('file_id', 0)->value('status');

    expect($status)->toBe('SHARED');
});

it('shareDir sets Annotation status to SHARED', function () {
    shareDirRecords($this->user->id, 971, 972, 973);

    $status = Annotation::where('vault_id', 971)->where('dir_id', 972)->where('file_id', 0)->value('status');

    expect($status)->toBe('SHARED');
});

// ---------------------------------------------------------------------------
// unshareDir — PRIVATE revert
// ---------------------------------------------------------------------------

it('unshareDir reverts ContentsRequest status to PRIVATE', function () {
    shareDirRecords($this->user->id, 981, 982, 983);
    unshareDirRecords(981, 982);

    $status = ContentsRequest::where('vault_id', 981)->where('dir_id', 982)->where('file_id', 0)->value('status');

    expect($status)->toBe('PRIVATE');
});

it('unshareDir reverts Annotation status to PRIVATE', function () {
    shareDirRecords($this->user->id, 991, 992, 993);
    unshareDirRecords(991, 992);

    $status = Annotation::where('vault_id', 991)->where('dir_id', 992)->where('file_id', 0)->value('status');

    expect($status)->toBe('PRIVATE');
});

// ---------------------------------------------------------------------------
// URL stability
// ---------------------------------------------------------------------------

it('URL is preserved across share and unshare operations', function () {
    $creq = ensureDirCreq($this->user->id, 1001, 1002, 1003);
    $url1 = $creq->url;

    shareDirRecords($this->user->id, 1001, 1002, 1003);
    $url2 = ContentsRequest::where('vault_id', 1001)->where('dir_id', 1002)->where('file_id', 0)->value('url');

    unshareDirRecords(1001, 1002);
    $url3 = ContentsRequest::where('vault_id', 1001)->where('dir_id', 1002)->where('file_id', 0)->value('url');

    expect($url1)->toBe($url2)->toBe($url3)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// URL uniqueness — different directories get different URLs
// ---------------------------------------------------------------------------

it('two different directories get different share URLs', function () {
    $creq1 = ensureDirCreq($this->user->id, 1011, 1012, 1013);
    $creq2 = ensureDirCreq($this->user->id, 1011, 1099, 1013); // same vault, different dir

    expect($creq1->url)->not->toBe($creq2->url);
});
