<?php

/**
 * The module install page is presented to admins as "Software Updates" in the
 * admin panel (nav entry + page title), not the class-derived "Manage Modules".
 */

use App\Filament\Pages\ManageModules;

it('labels the module page as "Software Updates"', function () {
    expect(ManageModules::getNavigationLabel())->toBe('Software Updates')
        ->and((new ManageModules)->getTitle())->toBe('Software Updates');
});
