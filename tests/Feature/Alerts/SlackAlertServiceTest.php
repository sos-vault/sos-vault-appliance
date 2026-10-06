<?php

use App\Services\SlackAlertService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function slackService(MockHandler $mock): SlackAlertService
{
    return new SlackAlertService(new Client(['handler' => HandlerStack::create($mock)]));
}

it('send() returns true on a 2xx response', function () {
    $service = slackService(new MockHandler([new Response(200, [], 'ok')]));

    expect($service->send('https://hooks.slack.com/services/x', ['name' => 'a', 'severity' => 'WARNING']))->toBeTrue();
});

it('send() returns false on a non-2xx response', function () {
    $service = slackService(new MockHandler([new Response(500, [], 'error')]));

    expect($service->send('https://hooks.slack.com/services/x', ['name' => 'a', 'severity' => 'WARNING']))->toBeFalse();
});

it('send() returns false and does not throw on a connection failure', function () {
    $mock = new MockHandler([
        new ConnectException('could not connect', new Request('POST', 'https://hooks.slack.com/services/x')),
    ]);
    $service = slackService($mock);

    expect($service->send('https://hooks.slack.com/services/x', ['name' => 'a', 'severity' => 'WARNING']))->toBeFalse();
});

it('test() posts a synthetic message and returns true on success', function () {
    $service = slackService(new MockHandler([new Response(200, [], 'ok')]));

    expect($service->test('https://hooks.slack.com/services/x'))->toBeTrue();
});
