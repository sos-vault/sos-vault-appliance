<?php

use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\FileContent;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;

/**
 * Collaboration on shared documents (regression for the two bugs the IDOR
 * write-gate introduced):
 *   - a non-owner who can read a shared, UNLOCKED document may add annotation
 *     content (title/acetate) but may never change its share/lock/expiry state;
 *   - a LOCKED document, or one that is not shared, is owner-only.
 *
 * setRows() only touches the ContentsRequest / Annotation rows (no vault mount),
 * so it can be exercised directly against the DB.
 */
beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function svaultShareFileFixture(int $vid, int $did, int $fid, int $ownerId, string $reqStatus = 'SHARED', string $annStatus = 'SHARED'): void
{
    ContentsRequest::factory()->create([
        'vault_id' => $vid, 'dir_id' => $did, 'file_id' => $fid,
        'owner' => $ownerId, 'status' => $reqStatus,
    ]);
    Annotation::factory()->create([
        'vault_id' => $vid, 'dir_id' => $did, 'file_id' => $fid,
        'owner' => $ownerId, 'status' => $annStatus, 'acetate' => 'owner-note',
    ]);
}

function svaultWriteAnnotation(int $vid, int $did, int $fid, array $data): void
{
    FileContent::withParameters(['vid' => $vid, 'did' => $did, 'fid' => $fid, 'cid' => 1])
        ->setRows($data);
}

it('lets a recipient annotate an unlocked shared document (content only)', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $recipient = User::factory()->create();
    svaultShareFileFixture($vault->id, 16, 4961, $owner->id, 'SHARED', 'SHARED');

    $this->actingAs($recipient);
    svaultWriteAnnotation($vault->id, 16, 4961, [
        'acetate' => 'recipient-note', 'title' => 'hi', 'locked' => 'LOCKED', 'status' => 'x',
    ]);

    $ann = Annotation::where(['vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961])->first();
    $req = ContentsRequest::where(['vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961])->first();

    expect($ann->acetate)->toBe('recipient-note') // content saved
        ->and($ann->status)->toBe('SHARED')        // share state untouched
        ->and($req->status)->toBe('SHARED');       // recipient could NOT change lock/share
});

it('blocks a recipient from annotating a LOCKED document', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $recipient = User::factory()->create();
    svaultShareFileFixture($vault->id, 16, 4961, $owner->id, 'LOCKED', 'SHARED');

    $this->actingAs($recipient);
    svaultWriteAnnotation($vault->id, 16, 4961, ['acetate' => 'recipient-note', 'title' => 'hi']);

    $ann = Annotation::where(['vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961])->first();
    expect($ann->acetate)->toBe('owner-note'); // unchanged
});

it('blocks a stranger with no share from writing annotations', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $stranger = User::factory()->create();
    svaultShareFileFixture($vault->id, 16, 4961, $owner->id, 'SHARED', 'PRIVATE'); // unshared

    $this->actingAs($stranger);
    svaultWriteAnnotation($vault->id, 16, 4961, ['acetate' => 'evil', 'title' => 'x']);

    $ann = Annotation::where(['vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961])->first();
    expect($ann->acetate)->toBe('owner-note'); // unchanged
});

it('mints an unguessable share token (not derivable from the file ids)', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    $this->actingAs($owner);
    // Sharing a fresh file creates the ContentsRequest with its share URL.
    svaultWriteAnnotation($vault->id, 16, 4961, ['shared' => 'SHARED']);

    $req = ContentsRequest::where(['vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961])->first();
    $oldDerivable = url('sosShared/'.hash('sha256', "{$owner->id}/{$vault->id}/16/4961/filebrowser"));

    expect($req)->not->toBeNull()
        ->and($req->url)->toMatch('#/sosShared/[A-Za-z0-9]{40}$#') // random 40-char token
        ->and($req->url)->not->toBe($oldDerivable);                 // not the legacy predictable hash
});

it('lets the owner change share and lock state', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    svaultShareFileFixture($vault->id, 16, 4961, $owner->id, 'SHARED', 'SHARED');

    $this->actingAs($owner);
    svaultWriteAnnotation($vault->id, 16, 4961, ['locked' => 'LOCKED', 'acetate' => 'owner-2', 'title' => 't']);

    $req = ContentsRequest::where(['vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961])->first();
    $ann = Annotation::where(['vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961])->first();
    expect($req->status)->toBe('LOCKED')      // owner changed lock
        ->and($ann->acetate)->toBe('owner-2'); // and content
});
