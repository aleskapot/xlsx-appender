<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\HeaderConflictException;
use XlsxFastAppender\Exception\InvalidCellReferenceException;
use XlsxFastAppender\Exception\InvalidOptionException;
use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\Exception\LockTimeoutException;
use XlsxFastAppender\Exception\StartCellConflictException;
use XlsxFastAppender\Exception\UnsupportedArchiveException;
use XlsxFastAppender\Exception\WriteFailedException;

/**
 * Appends rows to an existing worksheet of an XLSX file (variant C:
 * ZipArchive + streaming XML re-splice).
 *
 * One append() run follows the pipeline from §2.1 of the specification:
 * open -> resolve -> read -> scan -> validate -> splice -> verify -> save.
 * The target file is replaced atomically by libzip; on any failure the
 * temporary files are removed and the original file stays byte-identical.
 */
final class XlsxAppender
{
    /**
     * @var list<string>
     */
    private const KNOWN_OPTIONS = [
        'sheet',
        'start_cell',
        'conflict_mode',
        'mode',
        'max_sheet_xml_size',
        'use_lock',
        'lock_timeout',
        'skip_nulls',
        'columns',
        'write_header',
        'strict_columns',
    ];

    private const DEFAULT_MAX_SHEET_XML_SIZE = 268435456;

    /**
     * Largest archive size supported without zip64 (C20).
     */
    private const MAX_ZIP32_SIZE = 4294967295;

    /**
     * Delay between non-blocking lock attempts, microseconds.
     */
    private const LOCK_POLL_INTERVAL_MICROSECONDS = 50000;

    private readonly ?string $sheet;

    private readonly CellAddress $startCell;

    private readonly string $conflictMode;

    private readonly int $maxSheetXmlSize;

    private readonly bool $skipNulls;

    /**
     * @var array<mixed>|null
     */
    private readonly ?array $columnsOption;

    private readonly bool $writeHeader;

    private readonly bool $strictColumns;

    private readonly string $mode;

    private readonly bool $useLock;

    private readonly float $lockTimeout;

    private ColumnMapper $mapper;

    /**
     * @param array<string, mixed>             $options    see §3.1 of the specification
     * @param (\Closure(): \ZipArchive)|null   $zipFactory @internal seam for tests
     *
     * @throws InvalidOptionException when an option is unknown or has an invalid type/value
     * @throws InvalidCellReferenceException when start_cell cannot be parsed (C18)
     */
    public function __construct(
        private readonly string $path,
        array $options = [],
        private readonly ?\Closure $zipFactory = null,
    ) {
        $unknown = array_diff(array_keys($options), self::KNOWN_OPTIONS);

        if ($unknown !== []) {
            throw new InvalidOptionException(\sprintf(
                'Unknown option(s): %s. Known options: %s.',
                implode(', ', $unknown),
                implode(', ', self::KNOWN_OPTIONS),
            ));
        }

        $sheet = $options['sheet'] ?? null;

        if ($sheet !== null && !\is_string($sheet)) {
            throw new InvalidOptionException(\sprintf(
                'Option "sheet" must be a string or null, got %s.',
                get_debug_type($sheet),
            ));
        }

        $this->sheet = $sheet;

        $startCell = $options['start_cell'] ?? 'A1';

        if (!\is_string($startCell)) {
            throw new InvalidOptionException(\sprintf(
                'Option "start_cell" must be a string, got %s.',
                get_debug_type($startCell),
            ));
        }

        $this->startCell = CellAddress::parse($startCell);

        $conflictMode = $options['conflict_mode'] ?? 'error';

        if ($conflictMode !== 'error' && $conflictMode !== 'clear' && $conflictMode !== 'overwrite') {
            throw new InvalidOptionException(\sprintf(
                'Option "conflict_mode" must be "error", "clear" or "overwrite", got %s.',
                \is_string($conflictMode) ? '"'.$conflictMode.'"' : get_debug_type($conflictMode),
            ));
        }

        $this->conflictMode = $conflictMode;

        $mode = $options['mode'] ?? 'inline_str';

        if ($mode !== 'inline_str' && $mode !== 'shared_strings') {
            throw new InvalidOptionException(\sprintf(
                'Option "mode" must be "inline_str" or "shared_strings", got %s.',
                \is_string($mode) ? '"'.$mode.'"' : get_debug_type($mode),
            ));
        }

        $this->mode = $mode;

        $maxSize = $options['max_sheet_xml_size'] ?? self::DEFAULT_MAX_SHEET_XML_SIZE;

        if (!\is_int($maxSize) || $maxSize < 1) {
            throw new InvalidOptionException(\sprintf(
                'Option "max_sheet_xml_size" must be a positive integer (bytes), got %s.',
                \is_int($maxSize) ? '(int) '.$maxSize : get_debug_type($maxSize),
            ));
        }

        $this->maxSheetXmlSize = $maxSize;

        $useLock = $options['use_lock'] ?? true;

        if (!\is_bool($useLock)) {
            throw new InvalidOptionException(\sprintf(
                'Option "use_lock" must be a bool, got %s.',
                get_debug_type($useLock),
            ));
        }

        $lockTimeout = $options['lock_timeout'] ?? 30.0;

        if ((!\is_int($lockTimeout) && !\is_float($lockTimeout)) || $lockTimeout <= 0) {
            throw new InvalidOptionException(\sprintf(
                'Option "lock_timeout" must be a positive number of seconds, got %s.',
                get_debug_type($lockTimeout),
            ));
        }

        $this->useLock = $useLock;
        $this->lockTimeout = (float) $lockTimeout;

        $skipNulls = $options['skip_nulls'] ?? true;

        if (!\is_bool($skipNulls)) {
            throw new InvalidOptionException(\sprintf(
                'Option "skip_nulls" must be a bool, got %s.',
                get_debug_type($skipNulls),
            ));
        }

        $this->skipNulls = $skipNulls;

        $columns = $options['columns'] ?? null;

        if ($columns !== null && !\is_array($columns)) {
            throw new InvalidOptionException(\sprintf(
                'Option "columns" must be an array or null, got %s.',
                get_debug_type($columns),
            ));
        }

        $this->columnsOption = $columns;

        $writeHeader = $options['write_header'] ?? false;

        if (!\is_bool($writeHeader)) {
            throw new InvalidOptionException(\sprintf(
                'Option "write_header" must be a bool, got %s.',
                get_debug_type($writeHeader),
            ));
        }

        $this->writeHeader = $writeHeader;

        $strictColumns = $options['strict_columns'] ?? false;

        if (!\is_bool($strictColumns)) {
            throw new InvalidOptionException(\sprintf(
                'Option "strict_columns" must be a bool, got %s.',
                get_debug_type($strictColumns),
            ));
        }

        $this->strictColumns = $strictColumns;

        $this->mapper = new ColumnMapper($columns, $strictColumns, null);
    }

    /**
     * Registers a record transformation used instead of the built-in
     * key-based / positional mapping (§3.1 of the specification).
     *
     * @param \Closure(mixed): array<mixed> $mapper
     */
    public function map(\Closure $mapper): self
    {
        $this->mapper = new ColumnMapper($this->columnsOption, $this->strictColumns, $mapper);

        return $this;
    }

    /**
     * Appends rows to the configured worksheet.
     *
     * Pipeline steps 0–2: capability check, advisory lock, open (§2.1, C12/C20).
     *
     * @param iterable<mixed> $records records as arrays or objects (see §3.1, §3.5)
     *
     * @throws Exception\AppenderException any package failure; on every failure
     *                                      the target file is left unchanged
     * @throws LockTimeoutException when the file stays locked for lock_timeout seconds (C12)
     * @throws UnsupportedArchiveException when the archive exceeds 4 GB (C20)
     *
     * @return int number of written data rows (the header row, when written, is not counted)
     */
    public function append(iterable $records): int
    {
        if (!is_file($this->path)) {
            throw new InvalidWorkbookException(\sprintf(
                'The file "%s" does not exist; xlsx-fast-appender only appends to existing XLSX files.',
                $this->path,
            ));
        }

        $this->assertArchiveSizeSupported();
        $this->assertWritable();

        $lock = $this->acquireLock();

        try {
            return $this->appendRows($records);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @param iterable<mixed> $records
     */
    private function appendRows(iterable $records): int
    {
        $zip = $this->openZip();
        $closed = false;

        try {
            $inspector = new WorkbookInspector($this->path, $zip);
            $sheetPath = $inspector->resolveSheet($this->sheet);
            $store = $this->loadSharedStrings($inspector);
            $result = $this->spliceInto($inspector, $sheetPath, $records, $store);

            if ($result->isEmpty()) {
                return 0;
            }

            if (!$zip->addFile($this->tmpPath(), $sheetPath)) {
                throw new WriteFailedException(\sprintf(
                    'Unable to write the spliced worksheet into "%s".',
                    $this->path,
                ));
            }

            if ($store !== null && $store->hasNewReferences()) {
                $this->writeSharedStrings($zip, $store);
            }

            $closed = true;

            if (!$zip->close()) {
                throw new WriteFailedException(\sprintf(
                    'ZipArchive failed to save "%s"; the file was left unchanged.',
                    $this->path,
                ));
            }

            return $result->rowsWritten;
        } finally {
            if (!$closed) {
                // Revert every pending entry replacement: close() would commit
                // a partial write (sheet updated but shared strings not) when an
                // exception fired after the first successful addFile().
                $zip->unchangeAll();
                $zip->close();
            }

            $this->cleanupTempFiles();
        }
    }

    /**
     * Runs steps 3–9 of the pipeline: read, scan, validate, splice, verify.
     * The zip entry is written by the caller only when rows were produced.
     *
     * @param iterable<mixed>           $records
     * @param SharedStringsStore|null   $store    shared-strings index for "shared_strings" mode (§3.2)
     */
    private function spliceInto(
        WorkbookInspector $inspector,
        string $sheetPath,
        iterable $records,
        ?SharedStringsStore $store,
    ): SpliceResult {
        $sheetXml = $inspector->readSheetXml($sheetPath, $this->maxSheetXmlSize);
        $scan = $inspector->scan($sheetXml);

        $startRow = $this->startCell->row;

        if ($this->conflictMode === 'error' && $startRow <= $scan->lastRow) {
            throw new StartCellConflictException(\sprintf(
                'start_cell %s points at row %d, but "%s" already contains data up to row %d; '
                .'rows can only be appended below the last existing row (C2/C3). '
                .'Use start_cell "%s%d" for the next free row.',
                $this->startCell->toString(),
                $startRow,
                $this->path,
                $scan->lastRow,
                ColumnName::of($this->startCell->column),
                $scan->lastRow + 1,
            ));
        }

        $headerLabels = null;

        if ($this->writeHeader) {
            if (!$scan->isEmpty()) {
                throw new HeaderConflictException(\sprintf(
                    'write_header=true was requested, but "%s" already contains %d row(s); '
                    .'a header can only initialize an empty sheet (C23).',
                    $this->path,
                    $scan->rowCount,
                ));
            }

            if ($startRow < 2) {
                throw new InvalidCellReferenceException(\sprintf(
                    'write_header=true needs row %d for the header, but start_cell %s is in row 1; '
                    .'use start_cell "A2" or lower (C22).',
                    $startRow - 1,
                    $this->startCell->toString(),
                ));
            }

            $headerLabels = $this->mapper->headerLabels();

            if ($headerLabels === null) {
                throw new InvalidOptionException(
                    'Option "write_header" requires the "columns" option to be configured.',
                );
            }
        }

        $startColumn = $this->startCell->column;
        $width = $this->mapper->width();

        if ($width !== null && $startColumn + $width - 1 > ExcelLimits::MAX_COLUMNS) {
            throw new InvalidCellReferenceException(\sprintf(
                'start_cell column %s with %d columns needs column %d, which exceeds '
                .'the Excel limit of %d columns (XFD) (C26).',
                ColumnName::of($startColumn),
                $width,
                $startColumn + $width - 1,
                ExcelLimits::MAX_COLUMNS,
            ));
        }

        $splicer = new SheetSplicer(new RowXmlGenerator($startColumn, $this->skipNulls, $store));

        return $splicer->splice(
            $sheetXml,
            $this->tmpPath(),
            $startRow,
            $scan->rowCount,
            $records,
            $this->mapper,
            $headerLabels,
            $this->conflictMode,
        );
    }

    /**
     * Loads the shared-strings index for "shared_strings" mode; null for the
     * default "inline_str" mode, which never touches xl/sharedStrings.xml.
     */
    private function loadSharedStrings(WorkbookInspector $inspector): ?SharedStringsStore
    {
        if ($this->mode !== 'shared_strings') {
            return null;
        }

        $xml = $inspector->readSharedStrings($this->maxSheetXmlSize);

        return $xml === null ? SharedStringsStore::withoutFile() : SharedStringsStore::fromXml($xml);
    }

    /**
     * Writes the rebuilt xl/sharedStrings.xml (and, when the part did not
     * exist, its content-type/relationship registrations) into the archive.
     */
    private function writeSharedStrings(\ZipArchive $zip, SharedStringsStore $store): void
    {
        $this->addPatchedEntry(
            $zip,
            $this->tmpPath().'.sst',
            $store->buildSstXml(),
            SharedStringsStore::SST_ENTRY,
            'shared strings part',
        );

        if ($store->sharedStringsFileExisted()) {
            return;
        }

        $contentTypes = $zip->getFromName(SharedStringsStore::CONTENT_TYPES_ENTRY);

        if ($contentTypes !== false) {
            $patched = $store->contentTypesXml($contentTypes);

            if ($patched !== null) {
                $this->addPatchedEntry(
                    $zip,
                    $this->tmpPath().'.ct',
                    $patched,
                    SharedStringsStore::CONTENT_TYPES_ENTRY,
                    'content types part',
                );
            }
        }

        $relationships = $zip->getFromName(SharedStringsStore::RELATIONSHIPS_ENTRY);

        if ($relationships !== false) {
            $patched = $store->relationshipsXml($relationships);

            if ($patched !== null) {
                $this->addPatchedEntry(
                    $zip,
                    $this->tmpPath().'.rels',
                    $patched,
                    SharedStringsStore::RELATIONSHIPS_ENTRY,
                    'workbook relationships part',
                );
            }
        }
    }

    private function addPatchedEntry(
        \ZipArchive $zip,
        string $tmpPath,
        string $content,
        string $entry,
        string $label,
    ): void {
        $this->writeTempFile($tmpPath, $content);

        if (!$zip->addFile($tmpPath, $entry)) {
            throw new WriteFailedException(\sprintf('Unable to write the %s into "%s".', $label, $this->path));
        }
    }

    private function writeTempFile(string $tmpPath, string $content): void
    {
        if (file_put_contents($tmpPath, $content) !== \strlen($content)) {
            throw new WriteFailedException(\sprintf('Unable to create the temporary file "%s".', $tmpPath));
        }
    }

    /**
     * Fails fast when the target archive is larger than 4 GB: v1 documents
     * zip64 support as unreliable across PHP builds (C20).
     *
     * @throws UnsupportedArchiveException when the file exceeds the 32-bit zip limit
     */
    private function assertArchiveSizeSupported(): void
    {
        $size = filesize($this->path);

        if ($size !== false && $size > self::MAX_ZIP32_SIZE) {
            throw new UnsupportedArchiveException(\sprintf(
                'The file "%s" is %d bytes; xlsx-fast-appender v1 supports archives up to '
                .'4 GB (zip64 support depends on the PHP build) (C20).',
                $this->path,
                $size,
            ));
        }
    }

    /**
     * Takes an exclusive advisory lock for the duration of one append run
     * (pipeline step 1; C12). The lock lives in a sidecar `<file>.lock`
     * instead of the target file itself: an exclusive flock() on the target
     * would make ZipArchive fail to open it for writing on Windows (empirical
     * check, PHP 8.3/win32), and a sidecar keeps every platform on the same
     * code path. The lock file is deliberately never unlinked — deleting a
     * lock file while another process polls it could split the mutual
     * exclusion across two inodes.
     *
     * Locking is polled non-blocking until lock_timeout elapses.
     *
     *
     * @throws LockTimeoutException when another process holds the lock for too long
     * @throws WriteFailedException when the lock file cannot be opened
     *
     * @return resource|null the held lock handle, or null when use_lock=false
     */
    private function acquireLock(): mixed
    {
        if (!$this->useLock) {
            return null;
        }

        $handle = fopen($this->lockFilePath(), 'c');

        if ($handle === false) {
            throw new WriteFailedException(\sprintf(
                'Unable to open the lock file "%s" (C12).',
                $this->lockFilePath(),
            ));
        }

        $deadline = microtime(true) + $this->lockTimeout;

        while (flock($handle, \LOCK_EX | \LOCK_NB) !== true) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new LockTimeoutException(\sprintf(
                    'Could not acquire a lock on "%s" within %g second(s); '
                    .'another process is appending to this file (C12).',
                    $this->path,
                    $this->lockTimeout,
                ));
            }

            usleep(self::LOCK_POLL_INTERVAL_MICROSECONDS);
        }

        return $handle;
    }

    /**
     * @param resource|null $lock handle returned by acquireLock()
     */
    private function releaseLock(mixed $lock): void
    {
        if ($lock === null) {
            return;
        }

        flock($lock, \LOCK_UN);
        fclose($lock);
    }

    private function lockFilePath(): string
    {
        return $this->path.'.lock';
    }

    private function assertWritable(): void
    {
        if (!is_writable($this->path)) {
            throw new WriteFailedException(\sprintf(
                'The file "%s" is not writable (C13).',
                $this->path,
            ));
        }

        $directory = \dirname($this->path);

        if (!is_writable($directory)) {
            throw new WriteFailedException(\sprintf(
                'The directory "%s" is not writable, a temporary file cannot be created there (C13).',
                $directory,
            ));
        }
    }

    private function openZip(): \ZipArchive
    {
        $zip = $this->zipFactory !== null ? ($this->zipFactory)() : new \ZipArchive();
        $result = $zip->open($this->path, \ZipArchive::CREATE);

        if ($result !== true) {
            throw new InvalidWorkbookException(\sprintf(
                'Unable to open "%s" for writing (ZipArchive error: code %d).',
                $this->path,
                $result,
            ));
        }

        return $zip;
    }

    private function tmpPath(): string
    {
        return $this->path.'.tmp';
    }

    private function cleanupTempFiles(): void
    {
        foreach (['', '.rows', '.sst', '.ct', '.rels'] as $suffix) {
            $path = $this->tmpPath().$suffix;

            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
