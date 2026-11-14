<?php

use App\Services\Ai\CaseContextBuilder;
use App\Services\Ai\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns empty context when no case matches the directory id', function () {
    // No SupportCase rows → no candidate vault → empty context, not a crash.
    // (Path resolution now goes case → owner vault; see resolveCasePath.)
    $out = (new CaseContextBuilder)->build(999999, 1, 'why is memory high?', ProviderProfile::for('openai'));

    expect($out)->toBe('');
});

it('digestFor returns empty when the case has no vault', function () {
    expect((new CaseContextBuilder)->digestFor(999999, 1))->toBe('');
});
