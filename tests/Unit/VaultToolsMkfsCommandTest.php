<?php

/**
 * VaultTools::buildMkfsCommand() is the single source of truth for how a
 * vault's LUKS mapper device is formatted. Both the personal (createVault)
 * and group (createGroupVault) provisioning paths route through it, so the
 * "-T ext4,news" dense inode_ratio (4096 vs the ext4 default 16384) applies
 * uniformly — sosreports are tiny-file-heavy and the default ratio would
 * exhaust inodes long before disk space.
 */

use App\Providers\VaultTools;

it('always requests the dense news inode ratio', function () {
    expect(VaultTools::buildMkfsCommand('abc123'))
        ->toContain('-T ext4,news');
});

it('formats the given mapper device', function () {
    expect(VaultTools::buildMkfsCommand('abc123'))
        ->toEndWith('/dev/mapper/abc123');
});

it('omits the -L label when none is given (personal vault path)', function () {
    $cmd = VaultTools::buildMkfsCommand('abc123');

    expect($cmd)->not->toContain('-L')
        ->and($cmd)->toBe('/bin/sudo /sbin/mkfs.ext4 -T ext4,news /dev/mapper/abc123');
});

it('applies the -L label when given (group vault path)', function () {
    $cmd = VaultTools::buildMkfsCommand('gabc123', 'gabc123');

    expect($cmd)->toBe('/bin/sudo /sbin/mkfs.ext4 -T ext4,news -L gabc123 /dev/mapper/gabc123');
});

it('treats an empty-string label the same as no label', function () {
    expect(VaultTools::buildMkfsCommand('abc123', ''))
        ->toBe(VaultTools::buildMkfsCommand('abc123'));
});
