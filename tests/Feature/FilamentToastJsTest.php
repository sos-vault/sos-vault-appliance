<?php

/*
|--------------------------------------------------------------------------
| filamentToastJs() — client-side toast builder
|--------------------------------------------------------------------------
|
| Builds window.FilamentNotification JS from a serialized notification array so
| drained toasts can be delivered via $this->js() without the Livewire snapshot
| round-trip that races/corrupts the shared notifications component.
|
*/

it('builds a client-side toast from a typical success notification', function () {
    $js = filamentToastJs([
        'title' => 'Repacked report.tar.xz',
        'icon' => 'phosphor-bell-ringing-duotone',
        'iconColor' => 'success',
        'status' => 'success',
        'duration' => 6000,
    ]);

    expect($js)
        ->toStartWith('new FilamentNotification()')
        ->toEndWith('.send();')
        ->toContain('.title("Repacked report.tar.xz")')
        ->toContain('.icon("phosphor-bell-ringing-duotone")')
        ->toContain('.iconColor("success")')
        ->toContain('.status("success")')
        ->toContain('.duration(6000)');
});

it('uses .persistent() for persistent notifications', function () {
    $js = filamentToastJs(['title' => 'Heads up', 'duration' => 'persistent']);

    expect($js)->toContain('.persistent()')
        ->not->toContain('.duration(');
});

it('omits optional chains when their values are empty', function () {
    $js = filamentToastJs(['title' => 'Just a title']);

    expect($js)->toBe('new FilamentNotification().title("Just a title").send();');
});

it('falls back to color then status for the icon color', function () {
    expect(filamentToastJs(['title' => 't', 'color' => 'danger']))->toContain('.iconColor("danger")');
    expect(filamentToastJs(['title' => 't', 'status' => 'warning']))->toContain('.iconColor("warning")');
});

it('json-encodes the title so quotes and markup cannot break out of the JS', function () {
    $js = filamentToastJs(['title' => 'O\'Brien said "hi" </script>']);

    // Valid JSON string literal — no raw quote or closing tag escapes the argument.
    expect($js)->toContain('.title("O\'Brien said \\"hi\\" <\\/script>")');
});
