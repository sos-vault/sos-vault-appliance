<?php

/**
 * App\Services\CompareTreeService — diff helpers used by the SOS Compare tool.
 *
 * Covers:
 *  - flatten() drops excluded root dirs (sys/proc/sos_logs).
 *  - flatten() emits one entry per file/link/empty-dir, keyed by full path.
 *  - flatten() strips noisy fields (id, sum, date, time, tz, owner, group) so
 *    two reports of the same file compare equal.
 *  - markNodes('different') sets __status on both sides.
 *  - markNodes('missing_left') copies the path into the left tree under its parent.
 *  - markNodes walks up multiple levels when the whole parent chain is missing.
 */

use App\Services\CompareTreeService;

/**
 * Build an stdClass node matching what VaultTools::get_node() produces.
 * $path is the *parent* path (with trailing slash, '' for root level).
 */
function compareNode(array $attrs): object
{
    $defaults = [
        'id' => '0',
        'name' => '',
        'path' => '',
        'type' => '-',
        'perms' => 'rw-r--r--',
        'owner' => 'root',
        'group' => 'root',
        'size' => 0,
        'date' => '2026-04-26',
        'time' => '12:00:00',
        'tz' => 'UTC',
        'sum' => '',
        'realpath' => '',
        'realtype' => '',
    ];

    return (object) array_merge($defaults, $attrs);
}

function compareTree(array $children): object
{
    $root = compareNode([
        'id' => '99999999',
        'name' => '/',
        'path' => '',
        'type' => 'd',
    ]);
    $root->nodes = $children;

    return (object) ['nodes' => [$root]];
}

it('flattens a tree and strips compared-out fields', function () {
    $tree = compareTree([
        compareNode(['id' => '1', 'name' => 'etc', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '2', 'name' => 'passwd', 'path' => 'etc/', 'size' => 100, 'sum' => 'aaa']),
            compareNode(['id' => '3', 'name' => 'hosts', 'path' => 'etc/', 'size' => 50, 'sum' => 'bbb']),
        ]]),
    ]);

    $flat = CompareTreeService::flatten($tree);

    expect(array_keys($flat))->toEqualCanonicalizing(['/etc/passwd', '/etc/hosts']);
    expect($flat['/etc/passwd'])->not->toHaveKey('id');
    expect($flat['/etc/passwd'])->not->toHaveKey('sum');
    expect($flat['/etc/passwd'])->not->toHaveKey('date');
    expect($flat['/etc/passwd'])->toHaveKeys(['name', 'path', 'type', 'perms', 'size']);
});

it('drops excluded root-level directories during flatten', function () {
    $tree = compareTree([
        compareNode(['id' => '1', 'name' => 'etc', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '2', 'name' => 'passwd', 'path' => 'etc/']),
        ]]),
        compareNode(['id' => '4', 'name' => 'sys', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '5', 'name' => 'kernel', 'path' => 'sys/']),
        ]]),
        compareNode(['id' => '6', 'name' => 'proc', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '7', 'name' => 'cmdline', 'path' => 'proc/']),
        ]]),
        compareNode(['id' => '8', 'name' => 'sos_logs', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '9', 'name' => 'log.txt', 'path' => 'sos_logs/']),
        ]]),
    ]);

    $flat = CompareTreeService::flatten($tree);

    expect(array_keys($flat))->toEqualCanonicalizing(['/etc/passwd']);
});

it('emits identical flat entries for two reports with the same file but different timestamps', function () {
    $left = compareTree([
        compareNode(['id' => '5', 'name' => 'fstab', 'path' => '', 'size' => 200, 'sum' => 'X', 'date' => '2026-01-01']),
    ]);
    $right = compareTree([
        compareNode(['id' => '999', 'name' => 'fstab', 'path' => '', 'size' => 200, 'sum' => 'X', 'date' => '2026-04-01']),
    ]);

    $a = CompareTreeService::flatten($left);
    $b = CompareTreeService::flatten($right);

    expect($a['/fstab'])->toEqual($b['/fstab']);
});

it('marks a different node on both origin and target', function () {
    $left = compareTree([
        compareNode(['id' => '1', 'name' => 'etc', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '2', 'name' => 'passwd', 'path' => 'etc/', 'size' => 100]),
        ]]),
    ]);
    $right = compareTree([
        compareNode(['id' => '1', 'name' => 'etc', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '2', 'name' => 'passwd', 'path' => 'etc/', 'size' => 200]),
        ]]),
    ]);

    $li = [];
    $ri = [];
    CompareTreeService::buildIndex($left->nodes, $li);
    CompareTreeService::buildIndex($right->nodes, $ri);

    $changes = [
        '/etc/passwd' => ['name' => 'passwd', 'path' => 'etc/', '__status' => 'different'],
    ];

    CompareTreeService::markNodes($changes, 'different', $right, $left, $ri, $li);

    expect($li['etc/|passwd']->__status ?? null)->toBe('different');
    expect($ri['etc/|passwd']->__status ?? null)->toBe('different');
});

it('copies a missing_left node into the left tree under its existing parent', function () {
    $left = compareTree([
        compareNode(['id' => '1', 'name' => 'etc', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '2', 'name' => 'passwd', 'path' => 'etc/']),
        ]]),
    ]);
    $right = compareTree([
        compareNode(['id' => '1', 'name' => 'etc', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '2', 'name' => 'passwd', 'path' => 'etc/']),
            compareNode(['id' => '3', 'name' => 'shadow', 'path' => 'etc/']),
        ]]),
    ]);

    $li = [];
    $ri = [];
    CompareTreeService::buildIndex($left->nodes, $li);
    CompareTreeService::buildIndex($right->nodes, $ri);

    $changes = [
        '/etc/shadow' => ['name' => 'shadow', 'path' => 'etc/', '__status' => 'missing_left'],
    ];

    CompareTreeService::markNodes($changes, 'missing_left', $right, $left, $ri, $li);

    // Right side: the source node is marked.
    expect($ri['etc/|shadow']->__status ?? null)->toBe('missing_left');

    // Left side: the etc dir now has a shadow child with __status set.
    $names = array_map(fn ($n) => $n->name, $left->nodes[0]->nodes[0]->nodes);
    expect($names)->toEqualCanonicalizing(['passwd', 'shadow']);
    $shadow = collect($left->nodes[0]->nodes[0]->nodes)->firstWhere('name', 'shadow');
    expect($shadow->__status ?? null)->toBe('missing_left');

    // The left index now resolves the new node.
    expect($li['etc/|shadow']->__status ?? null)->toBe('missing_left');
});

it('copies a parent chain when multiple ancestor dirs are missing', function () {
    // Left has only the root; right has /a/b/c/file.
    $left = compareTree([]);
    $right = compareTree([
        compareNode(['id' => '1', 'name' => 'a', 'path' => '', 'type' => 'd', 'nodes' => [
            compareNode(['id' => '2', 'name' => 'b', 'path' => 'a/', 'type' => 'd', 'nodes' => [
                compareNode(['id' => '3', 'name' => 'c', 'path' => 'a/b/', 'type' => 'd', 'nodes' => [
                    compareNode(['id' => '4', 'name' => 'file', 'path' => 'a/b/c/']),
                ]]),
            ]]),
        ]]),
    ]);

    $li = [];
    $ri = [];
    CompareTreeService::buildIndex($left->nodes, $li);
    CompareTreeService::buildIndex($right->nodes, $ri);

    $changes = [
        '/a/b/c/file' => ['name' => 'file', 'path' => 'a/b/c/', '__status' => 'missing_left'],
    ];

    CompareTreeService::markNodes($changes, 'missing_left', $right, $left, $ri, $li);

    // The whole /a/b/c/file chain ends up under left's root.
    expect($left->nodes[0]->nodes)->toHaveCount(1);
    $a = $left->nodes[0]->nodes[0];
    expect($a->name)->toBe('a');
    expect($a->__status ?? null)->toBe('missing_left');
    expect($a->nodes[0]->name)->toBe('b');
    expect($a->nodes[0]->nodes[0]->name)->toBe('c');
    expect($a->nodes[0]->nodes[0]->nodes[0]->name)->toBe('file');
});
