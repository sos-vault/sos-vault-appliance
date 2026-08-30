<?php

use App\Models\Bookmark;
use App\Models\FileList;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

// ---------------------------------------------------------------------------
// Bookmarks (and FileLists) are case-independent within a vault: a bookmark
// created while viewing one case shows in every case of the same vault, the
// per-case duplicate rows collapse to one button, and mutations apply across
// all sibling rows in the vault.
// ---------------------------------------------------------------------------

/**
 * @return array{0: User, 1: Vault, 2: SupportCase, 3: SupportCase}
 */
function vaultWithTwoCases(): array
{
    $user = User::factory()->create();
    $vault = Vault::factory()->create();

    $gid = $user->group_id ?? $user->id;

    $caseA = SupportCase::factory()->create([
        'vault_id' => $vault->id, 'file_id' => 1001, 'owner' => $user->id, 'group' => $gid,
    ]);
    $caseB = SupportCase::factory()->create([
        'vault_id' => $vault->id, 'file_id' => 1002, 'owner' => $user->id, 'group' => $gid,
    ]);

    return [$user, $vault, $caseA, $caseB];
}

function quickBookmark(User $user, Vault $vault, SupportCase $case, array $overrides = []): Bookmark
{
    return Bookmark::factory()->create(array_merge([
        'user_id' => $user->id,
        'vault_id' => $vault->id,
        'case_id' => $case->id,
        'dir_id' => $case->file_id,
        'filelist_id' => null,
        'name' => 'cpuinfo',
        'fullpath' => 'proc/cpuinfo',
        'filetype' => 'file',
    ], $overrides));
}

function mountToolControls(User $user, SupportCase $case)
{
    return Livewire::actingAs($user)->test('tool-controls', [
        'caseid' => $case->id,
        'parent' => 'sosBrowser',
        'color' => 'primary',
    ]);
}

// ---------------------------------------------------------------------------
// Quick bookmarks
// ---------------------------------------------------------------------------

it('shows a bookmark created in one case while viewing another case of the same vault', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();
    quickBookmark($user, $vault, $caseA, ['name' => 'cpuinfo']);

    $component = mountToolControls($user, $caseB);

    expect($component->instance()->getBookmarks())->toHaveCount(1);
});

it('collapses identical per-case bookmark rows into a single button', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();
    quickBookmark($user, $vault, $caseA);
    quickBookmark($user, $vault, $caseB); // same name/fullpath/filetype

    $component = mountToolControls($user, $caseB);

    expect($component->instance()->getBookmarks())->toHaveCount(1);
});

it('keeps distinct bookmarks separate', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();
    quickBookmark($user, $vault, $caseA, ['name' => 'cpuinfo', 'fullpath' => 'proc/cpuinfo']);
    quickBookmark($user, $vault, $caseA, ['name' => 'meminfo', 'fullpath' => 'proc/meminfo']);

    $component = mountToolControls($user, $caseB);

    expect($component->instance()->getBookmarks())->toHaveCount(2);
});

it('does not show bookmarks from a different vault', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();

    $otherVault = Vault::factory()->create();
    $otherCase = SupportCase::factory()->create([
        'vault_id' => $otherVault->id, 'file_id' => 2001, 'owner' => $user->id,
    ]);
    quickBookmark($user, $otherVault, $otherCase, ['name' => 'cpuinfo']);

    $component = mountToolControls($user, $caseB);

    expect($component->instance()->getBookmarks())->toHaveCount(0);
});

it('deletes every per-case copy of a bookmark in the vault', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();
    $bA = quickBookmark($user, $vault, $caseA);
    quickBookmark($user, $vault, $caseB);

    mountToolControls($user, $caseB)->call('delBookmark', $bA->id);

    expect(Bookmark::where('vault_id', $vault->id)
        ->where('name', 'cpuinfo')->where('fullpath', 'proc/cpuinfo')->count())
        ->toBe(0);
});

// ---------------------------------------------------------------------------
// FileLists
// ---------------------------------------------------------------------------

function fileListWithMember(User $user, Vault $vault, SupportCase $case, string $name, string $member): FileList
{
    $list = FileList::factory()->create([
        'user_id' => $user->id, 'vault_id' => $vault->id,
        'case_id' => $case->id, 'dir_id' => $case->file_id, 'name' => $name, 'title' => $name,
    ]);

    Bookmark::factory()->create([
        'user_id' => $user->id, 'vault_id' => $vault->id, 'case_id' => $case->id,
        'dir_id' => $case->file_id, 'filelist_id' => $list->id,
        'name' => $member, 'fullpath' => "proc/{$member}", 'filetype' => 'file',
    ]);

    return $list;
}

it('collapses same-named FileLists across cases into one button', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();
    fileListWithMember($user, $vault, $caseA, 'Process', 'ps');
    fileListWithMember($user, $vault, $caseB, 'Process', 'pstree');

    $component = mountToolControls($user, $caseB);

    expect($component->instance()->getFilelists())->toHaveCount(1);
});

it('renames every same-named FileList in the vault', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();
    $listA = fileListWithMember($user, $vault, $caseA, 'Process', 'ps');
    fileListWithMember($user, $vault, $caseB, 'Process', 'pstree');

    mountToolControls($user, $caseB)->call('renameFileList', $listA->id, 'Tasks');

    expect(FileList::where('vault_id', $vault->id)->where('name', 'Process')->count())->toBe(0);
    expect(FileList::where('vault_id', $vault->id)->where('name', 'Tasks')->count())->toBe(2);
});

it('deletes every same-named FileList and its bookmarks in the vault', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();
    $listA = fileListWithMember($user, $vault, $caseA, 'Process', 'ps');
    fileListWithMember($user, $vault, $caseB, 'Process', 'pstree');

    mountToolControls($user, $caseB)->call('delFileList', $listA->id);

    expect(FileList::where('vault_id', $vault->id)->where('name', 'Process')->count())->toBe(0);
    expect(Bookmark::where('vault_id', $vault->id)->whereNotNull('filelist_id')->count())->toBe(0);
});

it('removes a member from every same-named FileList in the vault', function () {
    [$user, $vault, $caseA, $caseB] = vaultWithTwoCases();
    $listA = fileListWithMember($user, $vault, $caseA, 'Process', 'ps');
    fileListWithMember($user, $vault, $caseB, 'Process', 'ps');

    mountToolControls($user, $caseB)->call('editFileList', $listA->id, ['ps']);

    expect(Bookmark::where('vault_id', $vault->id)->where('name', 'ps')->count())->toBe(0);
});
