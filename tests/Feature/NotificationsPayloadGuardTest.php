<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Corrupt Filament notifications payload guard
|--------------------------------------------------------------------------
|
| A concurrent-update race on the shared Filament notifications toast component
| can leave a corrupt snapshot in the browser, whose next poll throws
| `Collection::fromLivewire(): ... array, int given`. Because that toast renders
| in both the app and marketing (login) layouts, the raw 500 wedged every page
| including login. The guard in bootstrap/app.php downgrades exactly this
| TypeError on `livewire/update` to a 419 (so Livewire self-heals) and keeps
| unrelated TypeErrors untouched.
|
*/

function notificationsHydrationTypeError(): TypeError
{
    return new TypeError(
        'Filament\Notifications\Collection::{closure:Filament\Notifications\Collection::fromLivewire():32}(): '.
        'Argument #1 ($notification) must be of type array, int given'
    );
}

function notificationsPropertyTypeError(): TypeError
{
    return new TypeError(
        'Cannot assign array to property Filament\Notifications\Livewire\Notifications::'.
        '$isFilamentNotificationsComponent of type bool'
    );
}

function renderException(TypeError $e, string $path): Response
{
    $request = Request::create($path, 'POST');

    return app(ExceptionHandler::class)->render($request, $e);
}

it('returns 419 for the corrupt notifications payload on a livewire update', function () {
    $response = renderException(notificationsHydrationTypeError(), '/livewire/update');

    expect($response->getStatusCode())->toBe(419);
});

it('returns 419 for the corrupt notifications property assignment on a livewire update', function () {
    $response = renderException(notificationsPropertyTypeError(), '/livewire/update');

    expect($response->getStatusCode())->toBe(419);
});

it('suppresses default reporting of the corrupt property assignment but logs a warning', function () {
    Log::spy();

    app(ExceptionHandler::class)->report(notificationsPropertyTypeError());

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'corrupt Filament notifications payload'));
});

it('does not hijack the same TypeError outside the livewire update endpoint', function () {
    $response = renderException(notificationsHydrationTypeError(), '/dashboard');

    expect($response->getStatusCode())->not->toBe(419);
});

it('does not hijack unrelated TypeErrors on the livewire update endpoint', function () {
    $unrelated = new TypeError('App\\Whatever::handle(): Argument #1 ($foo) must be of type array, int given');

    $response = renderException($unrelated, '/livewire/update');

    expect($response->getStatusCode())->not->toBe(419);
});

it('suppresses default reporting of the corrupt payload but logs a warning', function () {
    Log::spy();

    app(ExceptionHandler::class)->report(notificationsHydrationTypeError());

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'corrupt Filament notifications payload'));
});

it('still reports unrelated TypeErrors normally', function () {
    Log::spy();

    app(ExceptionHandler::class)->report(
        new TypeError('App\\Whatever::handle(): Argument #1 ($foo) must be of type array, int given')
    );

    Log::shouldNotHaveReceived('warning');
});
