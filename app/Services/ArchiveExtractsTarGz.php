<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Provides a helper to work around PHP PharData's inability to read file
 * content from compressed .tar.gz archives via the phar:// stream wrapper.
 * The workaround decompresses to a plain .tar first, operates on that, then
 * cleans up the temporary .tar.
 */
trait ArchiveExtractsTarGz
{
    /**
     * Decompress a .tar.gz file to a temporary .tar, run the callback with the
     * path of the plain .tar, then clean up.
     *
     * @template T
     *
     * @param  callable(string $tarPath): T  $callback
     * @return T
     */
    private function withDecompressedTar(string $gzPath, callable $callback): mixed
    {
        $tarPath = sys_get_temp_dir().'/archive-'.uniqid().'.tar';

        $gz = gzopen($gzPath, 'rb');
        $out = fopen($tarPath, 'wb');

        while (! gzeof($gz)) {
            fwrite($out, gzread($gz, 65536));
        }

        gzclose($gz);
        fclose($out);

        try {
            return $callback($tarPath);
        } finally {
            if (file_exists($tarPath)) {
                unlink($tarPath);
            }
        }
    }

    /**
     * Extract a .tar.gz archive to the given destination directory.
     */
    private function extractTarGz(string $gzPath, string $destDir): void
    {
        File::ensureDirectoryExists($destDir);

        $this->withDecompressedTar($gzPath, function (string $tarPath) use ($destDir): void {
            $phar = new \PharData($tarPath);
            $phar->extractTo($destDir, null, true);
        });
    }
}
