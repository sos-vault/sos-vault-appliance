<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Sprint 5 Step D — wrapper around sysadmin/cert-helper.
 *
 * All TLS certificate operations on the appliance go through this service so
 * PHP never invokes /bin/cp or /usr/bin/openssl directly. The helper script
 * lives at config('appliance.cert_helper'); tests override that path or use
 * Process::fake() to stub responses. nginx is not reloaded live — the operator
 * restarts the appliance to apply a new server cert or corporate CA.
 */
class CertificateManagerService
{
    /**
     * Stage the supplied fullchain / privkey PEMs to a tmp file each, hand
     * them to the helper, then unlink the staged files. The helper is the
     * one that runs `openssl x509 -noout` to validate parseability before
     * touching the live cert path.
     */
    public function install(string $fullchainPem, string $privkeyPem): void
    {
        $fullchain = $this->stageTmpFile($fullchainPem, 'sosvault-cert-fullchain.pem');
        $privkey = $this->stageTmpFile($privkeyPem, 'sosvault-cert-privkey.pem');
        try {
            $this->run(['install', $fullchain, $privkey], 'install certificate');
        } finally {
            @unlink($fullchain);
            @unlink($privkey);
        }
    }

    /**
     * Regenerate the self-signed certificate in place — the "revert to
     * self-signed" action when an operator wants to drop a custom cert. Takes
     * effect after the operator restarts the appliance.
     */
    public function generateSelfSigned(): void
    {
        $this->run(['self-signed'], 'generate self-signed certificate');
    }

    /**
     * Drop a PEM-encoded corporate root CA into the system trust store and
     * trigger update-ca-certificates so outbound HTTPS calls (Paddle webhook
     * verification, license server) trust internal endpoints.
     */
    public function installCorpCa(string $caPem): void
    {
        $ca = $this->stageTmpFile($caPem, 'sosvault-cert-corpca.pem');
        try {
            $this->run(['install-corp-ca', $ca], 'install corporate CA');
        } finally {
            @unlink($ca);
        }
    }

    /**
     * Read subject / issuer / expiry off the currently-installed cert.
     *
     * @return array{subject: string, issuer: string, expires_at: ?Carbon}
     */
    public function inspect(): array
    {
        $out = trim($this->run(['inspect'], 'inspect certificate'));
        $fields = ['subject' => '', 'issuer' => '', 'expires_at' => null];

        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'subject=')) {
                $fields['subject'] = trim(substr($line, 8));
            } elseif (str_starts_with($line, 'issuer=')) {
                $fields['issuer'] = trim(substr($line, 7));
            } elseif (str_starts_with($line, 'notAfter=')) {
                $raw = trim(substr($line, 9));
                try {
                    $fields['expires_at'] = Carbon::parse($raw);
                } catch (\Throwable) {
                    $fields['expires_at'] = null;
                }
            }
        }

        return $fields;
    }

    /**
     * Stage PEM contents to a DETERMINISTIC tmp path (not a random tempnam).
     *
     * The helper copies the staged file into the app-owned, bind-mounted cert
     * dir (no sudo). The appliance is single-admin, so a fixed /tmp name is safe;
     * we unlink first to avoid writing through a pre-existing symlink and keep
     * the file 0600.
     */
    private function stageTmpFile(string $contents, string $name): string
    {
        $path = sys_get_temp_dir().'/'.$name;
        @unlink($path);
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to allocate staging tmp file.');
        }
        @chmod($path, 0600);

        return $path;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function run(array $args, string $label): string
    {
        $helper = config('appliance.cert_helper', base_path('sysadmin/cert-helper'));
        $result = Process::run(array_merge([$helper], $args));

        if ($result->failed()) {
            $err = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("{$label} failed: {$err}");
        }

        return $result->output();
    }
}
