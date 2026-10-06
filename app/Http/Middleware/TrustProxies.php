<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Only the private ranges that the app container can ever see as a direct
     * peer: the in-compose nginx (172.21.21.0/24, inside 172.16/12) and any
     * private-range reverse proxy / load balancer in front of it. A public
     * CDN (e.g. Cloudflare) terminates at nginx, not at the app container, so
     * the app never needs to trust public proxy IPs. Avoid '*', which lets any
     * upstream forge X-Forwarded-* (IP spoofing, throttle/abuse evasion).
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
