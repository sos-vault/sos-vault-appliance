<?php

// Regression: the legacy Wave `docs::index` route referenced an unregistered
// `docs::` view namespace (wave/docs was removed), throwing
// "No hint path defined for [docs]." in production. The route is gone; /docs
// must no longer raise a 500.

it('does not 500 on the removed /docs route', function () {
    $response = $this->get('/docs');

    expect($response->status())->not->toBe(500);
});
