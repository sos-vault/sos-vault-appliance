<?php

namespace App\Console\Commands;

use App\Services\GpgService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Sprint 6 Step C — capture-server-report artisan command.
 *
 * Generates a sosreport of the appliance host (--batch --no-report so it
 * doesn't prompt and skips the HTML render), encrypts the resulting
 * .tar.xz archive to the SaaS support team's pubkey via GpgService, and
 * drops the encrypted blob at storage/app/private/server-report.tar.xz.gpg
 * for the operator to download via the admin UI / scp out of the box.
 *
 * Master plan §7.2; called by the installer (Sprint 6 Step D §7.1 step 16)
 * on first boot, AND available ad-hoc for support escalation.
 *
 * Cleanup: the plaintext sosreport tarball is unlinked in a finally{} block
 * on both success and failure paths so a crash never leaves an unencrypted
 * server snapshot on disk.
 */
class CaptureServerReport extends Command
{
    protected $signature = 'sos-vault:capture-server-report';

    protected $description = 'Generate a sosreport of the appliance host and GPG-encrypt it for support download.';

    public function handle(GpgService $gpg): int
    {
        if (! isAppliance()) {
            $this->error('sos-vault:capture-server-report only runs on appliance installs (config product.type=appliance).');

            return 1;
        }

        $sosreportBin = '/usr/sbin/sosreport';
        $stagingDir = sys_get_temp_dir().'/sos-vault-server-report-'.bin2hex(random_bytes(4));
        $outputPath = storage_path('app/private/server-report.tar.xz.gpg');

        if (! is_dir($stagingDir) && ! @mkdir($stagingDir, 0700, true)) {
            $this->error("Failed to create staging directory: {$stagingDir}");

            return 1;
        }

        $plaintextArchive = null;

        try {
            $this->info('Generating sosreport (this can take a couple of minutes)…');
            $result = Process::timeout(600)->run([
                $sosreportBin,
                '--batch',
                '--no-report',
                '--tmp-dir', $stagingDir,
                '--name', 'sos-vault',
            ]);

            if (! $result->successful()) {
                throw new RuntimeException('sosreport failed (exit '.$result->exitCode().'): '.trim($result->errorOutput() ?: $result->output()));
            }

            $archives = glob($stagingDir.'/sosreport-*.tar.xz') ?: [];
            if ($archives === []) {
                throw new RuntimeException('sosreport did not produce a .tar.xz archive in '.$stagingDir);
            }
            $plaintextArchive = $archives[0];

            @mkdir(dirname($outputPath), 0755, true);
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }

            $gpg->encrypt(
                inputPath: $plaintextArchive,
                outputPath: $outputPath,
                gpgHome: (string) config('license.gpg_home_verify'),
                recipient: (string) config('license.support_recipient'),
            );

            $this->info("Encrypted server report saved to: {$outputPath}");

            return 0;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        } finally {
            if ($plaintextArchive !== null && file_exists($plaintextArchive)) {
                @unlink($plaintextArchive);
            }
            foreach (glob($stagingDir.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            if (is_dir($stagingDir)) {
                @rmdir($stagingDir);
            }
        }
    }
}
