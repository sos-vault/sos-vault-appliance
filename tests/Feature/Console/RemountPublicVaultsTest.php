<?php

/**
 * vault:remount-public is invoked by the appliance boot service's
 * ExecStartPost. On a FRESH install that runs BEFORE the installer's migrate
 * step, so the DB has no schema yet. The command must no-op (not throw) so the
 * service's retry loop exits on the first iteration instead of grinding through
 * ~60s of "no such table" failures — the Step 11 "hang" reported on install.
 */

use Illuminate\Support\Facades\Schema;

it('succeeds without error when the vaults table does not exist yet', function () {
    Schema::dropIfExists('vaults');

    expect(Schema::hasTable('vaults'))->toBeFalse();

    $this->artisan('vault:remount-public')
        ->expectsOutputToContain('Vaults table not present yet')
        ->assertSuccessful();
});

it('reports no always_open vaults on a migrated but empty database', function () {
    // vaults table exists (migrated) but holds no always_open rows.
    $this->artisan('vault:remount-public')
        ->expectsOutputToContain('No always_open vaults found.')
        ->assertSuccessful();
});
