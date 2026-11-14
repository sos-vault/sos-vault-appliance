<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Guards the shipped appliance's llama.cpp service. The floating `:server` tag
 * once pulled an upstream build that renamed --no-mmap to --load-mode, so every
 * fresh install crash-looped the local AI assistant. The image must stay pinned
 * to a build, and the command must only use flags that build accepts.
 */
it('pins the llama image to a specific llama.cpp build', function () {
    $llama = Yaml::parseFile(base_path('docker-compose.appliance.yml'))['services']['llama'];

    expect($llama['image'])->toMatch('/^ghcr\.io\/ggml-org\/llama\.cpp:server-b\d+$/');
});

it('uses --load-mode, not the removed --no-mmap flag', function () {
    $llama = Yaml::parseFile(base_path('docker-compose.appliance.yml'))['services']['llama'];

    expect($llama['command'])->toContain('--load-mode none')
        ->and($llama['command'])->not->toContain('--no-mmap');
});
