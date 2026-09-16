<?php

use App\Models\Tools;

it('has an existing image file on disk for every tool shown on the landing page', function () {
    $missing = Tools::where('showInLandingPage', true)
        ->get()
        ->reject(fn ($tool) => file_exists(public_path($tool->image)))
        ->pluck('name');

    expect($missing)->toBeEmpty("Landing-page tools missing an image file: {$missing->implode(', ')}");
});
