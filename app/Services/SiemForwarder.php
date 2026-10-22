<?php

namespace App\Services;

use App\Models\Sysevent;
use Illuminate\Support\Facades\Log;

/**
 * Forwards recorded events to an external SIEM over Syslog (UDP / TCP / TLS).
 *
 * The wire format is selectable: Elastic Common Schema (ECS) JSON wrapped in an
 * RFC 5424 frame, or a native RFC 5424 structured-data line. Every message
 * carries an extra top-level LOGTYPE="sos-vault" field for easy identification
 * on the SIEM side. Configuration (all encrypted at rest under siem.*) is read
 * via siemConfig() in app/Helpers/sosVaultHelper.php.
 *
 * Called from the ForwardEventToSiem queued job so socket work never runs on
 * the web request path.
 */
class SiemForwarder
{
    /** IANA "reserved for examples" private enterprise number (RFC 5612). */
    private const ENTERPRISE_ID = 32473;

    /** Syslog facility local0. */
    private const FACILITY = 16;

    /** Connect / write timeout, seconds. */
    private const TIMEOUT = 5;

    /**
     * Whether a SIEM is configured and enabled. Used on the web request path by
     * sendSyslogEvent() to decide whether to dispatch the forwarding job.
     */
    public function isEnabled(): bool
    {
        return $this->enabled(siemConfig());
    }

    /**
     * Build and transmit the event to the SIEM. Any failure is logged and
     * swallowed — a broken SIEM must never break the app. Reads the config once.
     */
    public function forward(Sysevent $event): void
    {
        $cfg = siemConfig();

        if (! $this->enabled($cfg)) {
            return;
        }

        try {
            $this->transmit($this->wireMessage($event, $cfg), $cfg);
        } catch (\Throwable $e) {
            Log::error('SiemForwarder::forward failed: '.$e->getMessage());
        }
    }

    /**
     * The full wire message for the configured format. Both formats are framed
     * as RFC 5424; the ECS variant carries the ECS document as the MSG.
     */
    public function wireMessage(Sysevent $event, ?array $cfg = null): string
    {
        $format = ($cfg ?? siemConfig())['format'];

        if ($format === 'rfc5424') {
            return $this->buildRfc5424($event);
        }

        // ECS (JSON) over Syslog: RFC 5424 header, nil structured-data, ECS JSON MSG.
        return $this->syslogPrefix($event).' - '.json_encode(
            $this->buildEcs($event),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Run a connectivity self-test against the configured SIEM and return a
     * step-by-step trace. Unlike forward(), which is best-effort and swallows
     * its own errors, this surfaces exactly which stage failed — it backs the
     * "Send test event" button on Manage Settings and the "Send to SIEM" record
     * action on the Event Log.
     *
     * The forwarding-enabled toggle is intentionally NOT required here (only a
     * license, a host and a port), so an admin can validate connectivity before
     * turning forwarding on. Pass an existing event to re-send it verbatim; omit
     * it to send a synthetic SIEM_TEST event.
     *
     * @return array{ok: bool, steps: list<array{label: string, ok: bool, detail: string}>}
     */
    public function test(?Sysevent $event = null): array
    {
        $cfg = siemConfig();
        $steps = [];
        $add = function (string $label, bool $ok, string $detail) use (&$steps): void {
            $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };

        $licensed = $this->licensed();
        $add('License', $licensed, $licensed
            ? 'SIEM forwarding is available on this build.'
            : 'SIEM forwarding requires an active appliance license.');
        if (! $licensed) {
            return ['ok' => false, 'steps' => $steps];
        }

        $configured = $cfg['host'] !== '' && $cfg['port'] > 0;
        $add('Configuration', $configured, $configured
            ? sprintf('Destination %s://%s:%d, %s format.%s',
                $cfg['protocol'], $cfg['host'], $cfg['port'], strtoupper($cfg['format']),
                $cfg['enabled'] ? '' : ' Forwarding toggle is OFF — this test still sends.')
            : 'Set a SIEM host and port, then Save, before testing.');
        if (! $configured) {
            return ['ok' => false, 'steps' => $steps];
        }

        try {
            $message = $this->wireMessage($event ??= $this->testEvent(), $cfg);
            $add('Build message', true, sprintf('Built a %d-byte %s message for event %s.',
                strlen($message), strtoupper($cfg['format']), $event->id ? '#'.$event->id : '(synthetic test)'));
        } catch (\Throwable $e) {
            $add('Build message', false, $e->getMessage());

            return ['ok' => false, 'steps' => $steps];
        }

        try {
            $this->transmit($message, $cfg);
            $add('Send over '.strtoupper($cfg['protocol']), true, $cfg['protocol'] === 'udp'
                ? 'Datagram sent. UDP is connectionless, so the SIEM must confirm receipt on its side.'
                : 'Connected and wrote the message to the SIEM.');
        } catch (\Throwable $e) {
            $add('Send over '.strtoupper($cfg['protocol']), false, $e->getMessage());

            return ['ok' => false, 'steps' => $steps];
        }

        return ['ok' => true, 'steps' => $steps];
    }

    /**
     * A non-persisted synthetic event used by test() when no real event is given.
     */
    private function testEvent(): Sysevent
    {
        $event = new Sysevent([
            'type' => 'SIEM_TEST',
            'status' => 'SUCCESS',
            'class' => 'ACTIVITY',
            'owner' => auth()->id() ?? 0,
            'group' => 0,
            'vault_id' => 0,
            'dir_id' => 0,
            'case_id' => 0,
            'ip' => request()->ip() ?? '127.0.0.1',
            'payload' => json_encode(['test' => true, 'sent_at' => now()->toIso8601String()]),
        ]);
        $event->created_at = now();

        return $event;
    }

    private function enabled(array $cfg): bool
    {
        return $this->licensed()
            && $cfg['enabled'] && $cfg['host'] !== '' && $cfg['port'] > 0;
    }

    /**
     * Open-core gate: SIEM forwarding is a licensed feature on the appliance.
     * Always available on SaaS; on the appliance it requires a current license
     * (mirrors the Manage Settings section's visibility gate). Enforced here in
     * addition to the UI so a lapsed or removed license stops forwarding even
     * when siem.* settings remain configured from while the box was licensed —
     * this also closes the dispatch→lapse→worker race, since forward() and
     * isEnabled() both route through enabled().
     */
    private function licensed(): bool
    {
        return isSaas() || applianceLicensed();
    }

    /**
     * Map a Sysevent row to an Elastic Common Schema document.
     */
    public function buildEcs(Sysevent $event): array
    {
        $user = $event->ownerUser;
        $payload = json_decode($event->payload ?? '', true);

        return [
            '@timestamp' => ($event->created_at ?? now())->toIso8601String(),
            'event' => [
                'action' => $event->type,
                'outcome' => $this->outcome($event->status),
                'kind' => 'event',
                'category' => array_values(array_filter([strtolower((string) $event->class)])),
                'id' => (string) $event->id,
                'module' => 'sos-vault',
            ],
            'user' => array_filter([
                'id' => $event->owner ? (string) $event->owner : null,
                'name' => $user?->name,
            ]),
            'group' => ['id' => (string) $event->group],
            'source' => array_filter([
                'ip' => $event->ip,
                'geo' => array_filter([
                    'country_iso_code' => $event->iso_code,
                    'country_name' => $event->country,
                    'region_name' => $event->state,
                    'city_name' => $event->city,
                    'timezone' => $event->timezone,
                ]),
            ]),
            'sosvault' => [
                'vault_id' => (int) $event->vault_id,
                'dir_id' => (int) $event->dir_id,
                'case_id' => (int) $event->case_id,
                'status' => $event->status,
                'class' => $event->class,
                'payload' => $payload ?: new \stdClass,
            ],
            'message' => $this->summary($event, $user?->name),
            'LOGTYPE' => 'sos-vault',
        ];
    }

    /**
     * Build a native RFC 5424 line with a sos-vault structured-data element.
     */
    public function buildRfc5424(Sysevent $event): string
    {
        $sd = '[sosvault@'.self::ENTERPRISE_ID
            .' type="'.$this->sdEscape($event->type)
            .'" status="'.$this->sdEscape($event->status)
            .'" class="'.$this->sdEscape($event->class)
            .'" owner="'.$this->sdEscape((string) $event->owner)
            .'" group="'.$this->sdEscape((string) $event->group)
            .'" vault_id="'.$this->sdEscape((string) $event->vault_id)
            .'" case_id="'.$this->sdEscape((string) $event->case_id)
            .'" ip="'.$this->sdEscape((string) $event->ip)
            .'" LOGTYPE="sos-vault"]';

        return $this->syslogPrefix($event).' '.$sd.' '.$this->summary($event, $event->ownerUser?->name);
    }

    /**
     * RFC 5424 header up to and including MSGID:
     * <PRI>1 TIMESTAMP HOSTNAME APP-NAME PROCID MSGID
     */
    private function syslogPrefix(Sysevent $event): string
    {
        $pri = self::FACILITY * 8 + $this->severity($event->status);
        $timestamp = ($event->created_at ?? now())->format('Y-m-d\TH:i:s.uP');
        $hostname = gethostname() ?: '-';
        $msgid = $event->type ?: '-';

        return '<'.$pri.'>1 '.$timestamp.' '.$hostname.' sos-vault - '.$msgid;
    }

    private function transmit(string $message, array $cfg): void
    {
        $host = $cfg['host'];
        $port = $cfg['port'];
        $protocol = $cfg['protocol'];

        if ($protocol === 'udp') {
            // Datagram: send the message as-is, no framing.
            $client = @stream_socket_client("udp://{$host}:{$port}", $errno, $errstr, self::TIMEOUT);
        } elseif ($protocol === 'tls') {
            $client = @stream_socket_client(
                "tls://{$host}:{$port}",
                $errno,
                $errstr,
                self::TIMEOUT,
                STREAM_CLIENT_CONNECT,
                $this->tlsContext($cfg)
            );
            // Octet-counting framing (RFC 6587 / RFC 5425) for syslog-over-TLS.
            $message = strlen($message).' '.$message;
        } else { // tcp
            $client = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, self::TIMEOUT);
            // Non-transparent, newline-delimited framing (RFC 6587 §3.4.1). This
            // is what plain-TCP syslog receivers (rsyslog imtcp, Logstash/Splunk/
            // Graylog syslog inputs) expect by default; octet-counting is opt-in
            // on most of them and gets silently dropped, so the message must end
            // in a single LF and carry no length prefix.
            $message = rtrim($message, "\n")."\n";
        }

        if (! $client) {
            throw new \RuntimeException("cannot connect to {$protocol}://{$host}:{$port} ({$errno} {$errstr})");
        }

        stream_set_timeout($client, self::TIMEOUT);
        fwrite($client, $message);
        fclose($client);
    }

    /**
     * SSL stream context for one-way TLS: verify the SIEM server against the
     * uploaded CA (and/or the SIEM's own certificate, for a self-signed server).
     */
    private function tlsContext(array $cfg): mixed
    {
        $bundle = trim($cfg['ca_cert']."\n".$cfg['server_cert']);

        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $cfg['host'],
        ];

        if ($bundle !== '') {
            $path = tempnam(sys_get_temp_dir(), 'siemca_');
            file_put_contents($path, $bundle);
            $ssl['cafile'] = $path;
        }

        return stream_context_create(['ssl' => $ssl]);
    }

    private function summary(Sysevent $event, ?string $userName): string
    {
        $actor = $userName ?: ($event->owner ? 'user#'.$event->owner : 'UNKNOWN USER');

        return trim("{$event->type} {$event->status} by {$actor}");
    }

    private function outcome(?string $status): string
    {
        return str_contains(strtoupper((string) $status), 'FAIL') ? 'failure' : 'success';
    }

    /** RFC 5424 severity: info(6) for success, warning(4) for failures. */
    private function severity(?string $status): int
    {
        return str_contains(strtoupper((string) $status), 'FAIL') ? 4 : 6;
    }

    /** Escape a value for an RFC 5424 structured-data param (", \, ]). */
    private function sdEscape(?string $value): string
    {
        return str_replace(['\\', '"', ']'], ['\\\\', '\\"', '\\]'], (string) $value);
    }
}
