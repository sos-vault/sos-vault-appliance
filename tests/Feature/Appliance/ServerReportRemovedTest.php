<?php

use Illuminate\Support\Facades\Artisan;

/**
 * sos-vault:capture-server-report was a leftover from the original
 * sosreport-based licensing plan (replaced by the copy/paste machine key).
 * It could not run (no sosreport in the app image, ran as www-data) and
 * nothing consumed its output, so it was removed with its config key.
 */
it('no longer registers sos-vault:capture-server-report', function () {
    expect(Artisan::all())->not->toHaveKey('sos-vault:capture-server-report');
});

it('no longer configures a support GPG recipient', function () {
    expect(config('license'))->not->toHaveKey('support_recipient');
});
