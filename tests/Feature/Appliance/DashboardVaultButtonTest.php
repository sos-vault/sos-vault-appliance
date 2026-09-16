<?php

/**
 * The dashboard "Increase vault size" button opens the SaaS disk-plans modal
 * (paid storage tiers). On the appliance vault size is managed by the admin
 * (free, local LUKS resize), so the button must not render there — its gate
 * includes `! isAppliance()`.
 */
it('gates the dashboard vault-size button on a non-appliance build', function () {
    $blade = file_get_contents(base_path('resources/themes/anchor/pages/dashboard/index.blade.php'));

    expect($blade)->toContain('$canExpandVault = ! isAppliance()');
});
