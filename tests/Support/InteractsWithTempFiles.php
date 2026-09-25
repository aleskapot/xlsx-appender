<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Support;

/**
 * Temp-file and ZipArchive lifecycle helpers shared by PHPUnit- and
 * Testbench-based test cases (single inheritance prevents using the
 * TestCase base class directly).
 *
 * ZipArchive handles are closed before temp files are deleted: on Windows an
 * open archive handle keeps the file locked.
 */
trait InteractsWithTempFiles
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    /**
     * @var list<\ZipArchive>
     */
    private array $zips = [];

    protected function tempFilePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xla');

        if ($path === false) {
            self::fail('Unable to create a temporary file.');
        }

        // ZipArchive needs a non-existing or valid-zip path; start fresh.
        if (is_file($path)) {
            unlink($path);
        }

        $this->tempFiles[] = $path;

        return $path;
    }

    protected function openZip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            self::fail(\sprintf('Unable to open zip "%s".', $path));
        }

        return $this->trackZip($zip);
    }

    protected function trackZip(\ZipArchive $zip): \ZipArchive
    {
        $this->zips[] = $zip;

        return $zip;
    }

    protected function cleanupTempFiles(): void
    {
        foreach ($this->zips as $zip) {
            $zip->close();
        }

        $this->zips = [];

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }

            // sidecar lock files are never removed by the package itself
            $lockPath = $path.'.lock';

            if (is_file($lockPath)) {
                unlink($lockPath);
            }
        }

        $this->tempFiles = [];
    }
}
