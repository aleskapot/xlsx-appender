<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Support;

/**
 * ZipArchive double whose reads and close() can be forced to fail.
 *
 * - failReads emulates a race where an entry disappears between statName()
 *   and getFromName();
 * - armCloseFailure() makes close() behave like a failed save: parent::close()
 *   runs first (so libzip would have written the archive), then the original
 *   bytes are restored and false is returned — exactly how a failed save
 *   must leave the file unchanged.
 */
final class FailingZip extends \ZipArchive
{
    public bool $failReads = false;

    public bool $failAddFile = false;

    /**
     * When set, addFile() fails only for this exact zip entry name.
     */
    public ?string $failAddFileEntry = null;

    /**
     * @var string|null original file bytes; armed => next close() fails
     */
    private ?string $originalBytes = null;

    private string $backupPath = '';

    public function getFromName(string $name, int $len = 0, int $flags = 0): string|false
    {
        if ($this->failReads) {
            return false;
        }

        return parent::getFromName($name, $len, $flags);
    }

    /**
     * Captures the current bytes of $path so the next close() restores them.
     * Must be called before the archive is modified.
     */
    public function armCloseFailure(string $path): void
    {
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new \RuntimeException(\sprintf('Unable to read "%s" for the close-failure test.', $path));
        }

        $this->backupPath = $path;
        $this->originalBytes = $bytes;
    }

    public function addFile(string $filepath, string $entryname = '', int $start = 0, int $length = 0, int $flags = 0): bool
    {
        if ($this->failAddFile || ($this->failAddFileEntry !== null && $this->failAddFileEntry === $entryname)) {
            return false;
        }

        // Passing start/length/flags explicitly switches ZipArchive onto its
        // partial-file path, which refuses to replace an existing entry.
        // Forward the plain two-argument form for the normal case.
        if ($start === 0 && $length === 0 && $flags === 0) {
            return parent::addFile($filepath, $entryname);
        }

        return parent::addFile($filepath, $entryname, $start, $length, $flags);
    }

    public function close(): bool
    {
        if ($this->originalBytes === null) {
            return parent::close();
        }

        $bytes = $this->originalBytes;
        $path = $this->backupPath;
        $this->originalBytes = null;

        parent::close();
        file_put_contents($path, $bytes);

        return false;
    }
}
