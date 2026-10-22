<?php

namespace App\Jobs;

use App\Models\Sysevent;
use App\Services\SiemForwarder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Forwards one recorded event to the configured SIEM off the request path.
 *
 * Dispatched by sendSyslogEvent() (app/Helpers/sosVaultHelper.php) from within
 * addEvent(). Running on the queue means an unreachable or slow SIEM adds no
 * latency to the web request and gets retried on transient failure. Delivery is
 * best-effort: SiemForwarder logs and swallows its own errors.
 */
class ForwardEventToSiem implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public Sysevent $event) {}

    public function handle(SiemForwarder $forwarder): void
    {
        $forwarder->forward($this->event);
    }
}
