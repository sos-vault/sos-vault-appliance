<?php

namespace App\Http\Middleware;

use App\Services\TelegramService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AbuseIp
{
    protected array $whitelistedIPs;

    public function __construct()
    {
        // Fetch the whitelist from config
        $this->whitelistedIPs = config('abuseip.whitelist', []);
    }

    public function handle(Request $request, Closure $next)
    {
        $ip = $request->ip();

        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            // Check if the IP is whitelisted
            if (in_array($ip, $this->whitelistedIPs)) {
                return $next($request); // Allow request if IP is whitelisted
            }

            if (is_abused_ip($ip)) {
                // Side effects (log + Telegram notification) must never crash the
                // request: under a scanner flood these run on every hit, and a
                // broken log/pipe used to bubble up and take the worker down.
                // Throttle them to once per IP/hour so a flood can't fork-storm
                // the host, and swallow any failure.
                $this->notifyOnce($ip, $request);

                abort(403, sprintf('IP address %s has been Blocked.', $ip));
            }
        }

        return $next($request);
    }

    /**
     * Log and notify about a blocked IP, at most once per IP per hour, never
     * letting a logging or shell failure propagate into the request lifecycle.
     */
    protected function notifyOnce(string $ip, Request $request): void
    {
        try {
            if (! Cache::add('abuseip_notified:'.$ip, true, now()->addHour())) {
                return; // Already notified for this IP recently.
            }

            $message = sprintf("IP address %s has been Blocked.\nURL: %s", $ip, $request->url());

            $ipinfo = geoip($ip)['attributes'] ?? [];
            if (isset($ipinfo['country'], $ipinfo['city'])) {
                $message = sprintf('%s (%s %s)', $message, $ipinfo['country'], $ipinfo['city']);
            }

            Log::warning($message);

            app(TelegramService::class)->sendTelegramMessage("🚫 Blocked IP {$ip}\n\n{$message}");
        } catch (Throwable $e) {
            // Notification is best-effort; never block the 403 response on it.
        }
    }
}
