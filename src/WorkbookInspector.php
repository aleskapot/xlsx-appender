<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\AppenderSizeLimitException;
use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\Exception\SheetNotFoundException;

/**
 * Read-only inspection of an XLSX workbook held in a zip archive.
 *
 * Resolves a sheet name to its zip entry (workbook.xml -> workbook.xml.rels ->
 * worksheets/sheetN.xml) and scans worksheet XML for structural information
 * without keeping the whole sheet in memory.
 */
final class WorkbookInspector
{
    private const SPREADSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const DOCUMENT_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Resolved sheets: name => zip entry path, in workbook order.
     *
     * @var array<string, string>|null
     */
    private ?array $sheets = null;

    public function __construct(
        private readonly string $path,
        private readonly \ZipArchive $zip,
    ) {
    }

    /**
     * Sheet names in workbook order.
     *
     * @return list<string>
     */
    public function sheetNames(): array
    {
        return array_keys($this->sheets());
    }

    /**
     * Resolves a sheet name to its zip entry path; null selects the first sheet.
     *
     * @throws SheetNotFoundException when the name does not match any sheet
     * @throws InvalidWorkbookException when the workbook has no sheets or the part is missing
     */
    public function resolveSheet(?string $name): string
    {
        $sheets = $this->sheets();

        if ($name === null) {
            foreach ($sheets as $entry) {
                return $entry;
            }

            throw new InvalidWorkbookException(\sprintf('Workbook "%s" does not contain any sheets.', $this->path));
        }

        $entry = $sheets[$name] ?? null;

        if ($entry === null) {
            throw new SheetNotFoundException($name, array_keys($sheets));
        }

        return $this->requireEntry($entry);
    }

    /**
     * Reads a zip entry after enforcing the configured size limit.
     *
     * @throws AppenderSizeLimitException when the entry is larger than $maxBytes
     * @throws InvalidWorkbookException when the entry cannot be read
     */
    public function readSheetXml(string $sheetPath, int $maxBytes): string
    {
        $stat = $this->zip->statName($sheetPath);

        if ($stat === false) {
            throw new InvalidWorkbookException(\sprintf(
                'Worksheet part "%s" is referenced by the workbook but missing from "%s".',
                $sheetPath,
                $this->path,
            ));
        }

        if ($stat['size'] > $maxBytes) {
            throw new AppenderSizeLimitException(\sprintf(
                'Worksheet XML "%s" is %d bytes, which exceeds max_sheet_xml_size of %d bytes; '
                .'the sheet is too large for the in-memory variant of this package.',
                $sheetPath,
                $stat['size'],
                $maxBytes,
            ));
        }

        $xml = $this->zip->getFromName($sheetPath);

        if ($xml === false) {
            throw new InvalidWorkbookException(\sprintf(
                'Unable to read worksheet part "%s" from "%s".',
                $sheetPath,
                $this->path,
            ));
        }

        return $xml;
    }

    /**
     * Scans worksheet XML for the last existing row and row count.
     *
     * Streams with XMLReader: memory stays O(1) with respect to the sheet size.
     *
     * @throws InvalidWorkbookException when the XML is malformed (including an
     *                                   unclosed <sheetData>) or <sheetData> is missing (C14)
     */
    public function scan(string $sheetXml): SheetScanResult
    {
        // XMLReader::XML() rejects an empty source with a ValueError; a
        // zero-byte worksheet entry must surface as a domain exception.
        $reader = $sheetXml === '' ? false : \XMLReader::XML($sheetXml, null, \LIBXML_NONET);

        if (!$reader instanceof \XMLReader) {
            throw new InvalidWorkbookException(\sprintf('Worksheet XML in "%s" is not well-formed.', $this->path));
        }

        return SheetDataScanner::scanReader($reader, $this->path);
    }

    /**
     * Reads the shared strings part of the archive, enforcing the configured
     * size limit (§3.2 of the specification).
     *
     *
     * @throws AppenderSizeLimitException when the part is larger than $maxBytes
     * @throws InvalidWorkbookException when the part cannot be read
     *
     * @return string|null null when the workbook has no xl/sharedStrings.xml yet (C6)
     */
    public function readSharedStrings(int $maxBytes): ?string
    {
        $stat = $this->zip->statName(SharedStringsStore::SST_ENTRY);

        if ($stat === false) {
            return null;
        }

        if ($stat['size'] > $maxBytes) {
            throw new AppenderSizeLimitException(\sprintf(
                'Shared strings "%s" are %d bytes, which exceeds max_sheet_xml_size of %d bytes.',
                SharedStringsStore::SST_ENTRY,
                $stat['size'],
                $maxBytes,
            ));
        }

        $xml = $this->zip->getFromName(SharedStringsStore::SST_ENTRY);

        if ($xml === false) {
            throw new InvalidWorkbookException(\sprintf(
                'Unable to read shared strings part "%s" from "%s".',
                SharedStringsStore::SST_ENTRY,
                $this->path,
            ));
        }

        return $xml;
    }

    /**
     * Parses workbook.xml and workbook.xml.rels into name => zip entry path.
     *
     * @return array<string, string>
     */
    private function sheets(): array
    {
        if ($this->sheets !== null) {
            return $this->sheets;
        }

        $workbook = $this->parseXml($this->entryContents('xl/workbook.xml'), 'xl/workbook.xml');
        $rels = $this->parseXml(
            $this->entryContents('xl/_rels/workbook.xml.rels'),
            'xl/_rels/workbook.xml.rels',
        );

        $targets = [];

        foreach ($rels->getElementsByTagNameNS(self::PACKAGE_REL_NS, 'Relationship') as $relationship) {
            $id = $relationship->getAttribute('Id');
            $target = $relationship->getAttribute('Target');

            if ($id !== '' && $target !== '') {
                $targets[$id] = $this->normalizeTarget($target);
            }
        }

        $sheets = [];

        foreach ($workbook->getElementsByTagNameNS(self::SPREADSHEET_NS, 'sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $relationshipId = $sheet->getAttributeNS(self::DOCUMENT_REL_NS, 'id');
            $entry = $targets[$relationshipId] ?? null;

            if ($name === '' || $entry === null) {
                throw new InvalidWorkbookException(\sprintf(
                    'Sheet "%s" in "%s" references relationship "%s" which is not defined in xl/_rels/workbook.xml.rels.',
                    $name,
                    $this->path,
                    $relationshipId,
                ));
            }

            $sheets[$name] = $entry;
        }

        $this->sheets = $sheets;

        return $sheets;
    }

    /**
     * Turns a relationship Target (URI, relative to xl/) into a zip entry path.
     */
    private function normalizeTarget(string $target): string
    {
        if (str_starts_with($target, '/')) {
            $entry = ltrim($target, '/');
        } elseif (str_starts_with($target, 'xl/')) {
            $entry = $target;
        } else {
            $entry = 'xl/'.$target;
        }

        if ($this->zip->locateName($entry) !== false) {
            return $entry;
        }

        $decoded = rawurldecode($entry);

        if ($decoded !== $entry && $this->zip->locateName($decoded) !== false) {
            return $decoded;
        }

        return $entry;
    }

    private function requireEntry(string $entry): string
    {
        if ($this->zip->locateName($entry) === false) {
            throw new InvalidWorkbookException(\sprintf(
                'Worksheet part "%s" referenced by the workbook is missing from "%s".',
                $entry,
                $this->path,
            ));
        }

        return $entry;
    }

    private function entryContents(string $entry): string
    {
        $contents = $this->zip->getFromName($entry);

        if ($contents === false) {
            throw new InvalidWorkbookException(\sprintf(
                '"%s" is missing the required part "%s"; the file is not a valid XLSX workbook.',
                $this->path,
                $entry,
            ));
        }

        return $contents;
    }

    private function parseXml(string $xml, string $entry): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, \LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $detail = $errors === [] ? 'unknown parse error' : trim($errors[0]->message);

            throw new InvalidWorkbookException(\sprintf(
                'Part "%s" in "%s" is not well-formed XML: %s.',
                $entry,
                $this->path,
                $detail,
            ));
        }

        return $dom;
    }
}
