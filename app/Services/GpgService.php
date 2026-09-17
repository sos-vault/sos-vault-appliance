<?php

namespace App\Services;

use RuntimeException;

class GpgService
{
    /**
     * Sign a file using GPG with the private key in the given home directory.
     * Produces a signed (not encrypted) .gpg file at $outputPath.
     *
     * @throws RuntimeException
     */
    public function sign(string $inputPath, string $outputPath, string $gpgHome, string $passphrase = ''): void
    {
        $this->runGpg([
            '--homedir', $gpgHome,
            '--batch',
            '--yes',
            '--no-tty',
            '--pinentry-mode', 'loopback',
            '--passphrase', $passphrase,
            '--output', $outputPath,
            '--sign', $inputPath,
        ]);
    }

    /**
     * Produce a PGP clearsigned text file from $inputPath.
     * The output is a human-readable armored text block suitable for license files.
     *
     * @throws RuntimeException
     */
    public function clearsign(string $inputPath, string $outputPath, string $gpgHome, string $passphrase = ''): void
    {
        $this->runGpg([
            '--homedir', $gpgHome,
            '--batch',
            '--yes',
            '--no-tty',
            '--pinentry-mode', 'loopback',
            '--passphrase', $passphrase,
            '--output', $outputPath,
            '--clearsign', $inputPath,
        ]);
    }

    /**
     * Verify a PGP clearsigned file and extract the plaintext payload.
     * Returns the raw plaintext content on success.
     *
     * @throws RuntimeException
     */
    public function verifyClearsign(string $inputPath, string $gpgHome): string
    {
        // gpg --verify checks the signature; on failure it throws via runGpg.
        $this->runGpg([
            '--homedir', $gpgHome,
            '--batch',
            '--no-tty',
            '--verify', $inputPath,
        ]);

        // Extract the plaintext by decrypting (clearsign output is also --decrypt-able)
        $tmpOut = sys_get_temp_dir().'/gpg-verify-'.uniqid().'.txt';

        try {
            $this->runGpg([
                '--homedir', $gpgHome,
                '--batch',
                '--yes',
                '--no-tty',
                '--output', $tmpOut,
                '--decrypt', $inputPath,
            ]);

            return file_get_contents($tmpOut) ?: '';
        } finally {
            if (file_exists($tmpOut)) {
                unlink($tmpOut);
            }
        }
    }

    /**
     * Encrypt $inputPath to $outputPath using the public key in the given home
     * directory, addressed to $recipient (a user-id, email, or fingerprint
     * present in the keyring). Used by Sprint 6 Step C to wrap a sosreport
     * archive with the SaaS support team's pubkey before the operator
     * downloads it from the appliance — only the holder of the matching
     * private key can decrypt.
     *
     * --trust-model always lets us encrypt to a pubkey we never personally
     * signed (the appliance keyring is verify-only and ships with a single
     * imported pubkey).
     *
     * @throws RuntimeException
     */
    public function encrypt(string $inputPath, string $outputPath, string $gpgHome, string $recipient): void
    {
        $this->runGpg([
            '--homedir', $gpgHome,
            '--batch',
            '--yes',
            '--no-tty',
            '--trust-model', 'always',
            '--recipient', $recipient,
            '--output', $outputPath,
            '--encrypt', $inputPath,
        ]);
    }

    /**
     * Decrypt/verify a signed .gpg file using the public key in the given home directory.
     * Writes the extracted content to $outputPath.
     *
     * @throws RuntimeException
     */
    public function decrypt(string $inputPath, string $outputPath, string $gpgHome, string $passphrase = ''): void
    {
        $this->runGpg([
            '--homedir', $gpgHome,
            '--batch',
            '--yes',
            '--no-tty',
            '--pinentry-mode', 'loopback',
            '--passphrase', $passphrase,
            '--output', $outputPath,
            '--decrypt', $inputPath,
        ]);
    }

    /**
     * @param  array<string>  $args
     *
     * @throws RuntimeException
     */
    private function runGpg(array $args): void
    {
        $cmd = array_merge(['gpg'], $args);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Pass null to inherit the current environment; --homedir already controls the GPG home.
        $process = proc_open($cmd, $descriptors, $pipes, null, null);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start GPG process.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $detail = trim($stderr ?: $stdout);
            throw new RuntimeException("GPG failed (exit {$exitCode}): {$detail}");
        }
    }
}
