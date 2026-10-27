<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidOptionException;
use XlsxFastAppender\Exception\InvalidWorkbookException;

/**
 * Builds a new worksheet part and its workbook registrations.
 *
 * The patched XML is returned as a CreatedSheet so the caller can stage every
 * change at save time: ZipArchive does not expose newly added entries through
 * getFromName() before the archive is closed.
 *
 * The existing workbook, relationships and content-types XML is preserved
 * byte-for-byte outside the inserted elements; the new worksheet is appended
 * as the last sheet of the workbook.
 */
final class WorkbookSheetCreator
{
    private const WORKBOOK_ENTRY = 'xl/workbook.xml';

    private const RELATIONSHIPS_ENTRY = 'xl/_rels/workbook.xml.rels';

    private const CONTENT_TYPES_ENTRY = '[Content_Types].xml';

    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const DOCUMENT_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';

    private const RELATIONSHIP_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';

    private const MAX_SHEET_NAME_LENGTH = 31;

    private const FORBIDDEN_SHEET_NAME_CHARACTERS = ':\\/?*[]';

    public function __construct(
        private readonly string $path,
        private readonly \ZipArchive $zip,
    ) {
    }

    /**
     * Builds a new empty sheet and the patched workbook registration parts.
     *
     * @throws InvalidOptionException when the name is not a valid Excel sheet name
     * @throws InvalidWorkbookException when a required workbook part is missing or malformed
     */
    public function create(string $name): CreatedSheet
    {
        $this->assertValidName($name);

        $workbook = $this->readEntry(self::WORKBOOK_ENTRY);
        $relationships = $this->readEntry(self::RELATIONSHIPS_ENTRY);
        $contentTypes = $this->readEntry(self::CONTENT_TYPES_ENTRY);

        [$maxSheetId, $existingNames] = $this->sheetMetadata($workbook);
        $this->assertNameAvailable($name, $existingNames);

        $sheetId = $maxSheetId + 1;
        $relationshipId = $this->nextRelationshipId($relationships);
        $part = $this->nextWorksheetPart();
        $target = substr($part, \strlen('xl/'));

        [$relationshipPrefix, $workbook] = $this->relationshipPrefix($workbook);

        $workbook = $this->insertSheet($workbook, $name, $sheetId, $relationshipPrefix, $relationshipId);
        $relationships = self::insertIntoElement(
            $relationships,
            'Relationships',
            \sprintf(
                '<Relationship Id="%s" Type="%s" Target="%s"/>',
                $relationshipId,
                self::RELATIONSHIP_TYPE,
                $target,
            ),
            self::RELATIONSHIPS_ENTRY,
        );
        $contentTypes = self::insertIntoElement(
            $contentTypes,
            'Types',
            \sprintf(
                '<Override PartName="/%s" ContentType="%s"/>',
                $part,
                self::CONTENT_TYPE,
            ),
            self::CONTENT_TYPES_ENTRY,
        );

        return new CreatedSheet(
            $part,
            self::emptyWorksheetXml(),
            $workbook,
            $relationships,
            $contentTypes,
        );
    }

    private function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidOptionException(
                'Option "sheet" must not be empty when create_sheet_if_missing=true.',
            );
        }

        if (mb_strlen($name, 'UTF-8') > self::MAX_SHEET_NAME_LENGTH) {
            throw new InvalidOptionException(\sprintf(
                'Cannot create sheet "%s": Excel sheet names are limited to %d characters.',
                $name,
                self::MAX_SHEET_NAME_LENGTH,
            ));
        }

        if (strpbrk($name, self::FORBIDDEN_SHEET_NAME_CHARACTERS) !== false) {
            throw new InvalidOptionException(\sprintf(
                'Cannot create sheet "%s": Excel sheet names cannot contain any of %s.',
                $name,
                self::FORBIDDEN_SHEET_NAME_CHARACTERS,
            ));
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidOptionException(\sprintf(
                'Cannot create sheet "%s": Excel sheet names cannot contain control characters.',
                $name,
            ));
        }

        if (str_starts_with($name, "'") || str_ends_with($name, "'")) {
            throw new InvalidOptionException(\sprintf(
                'Cannot create sheet "%s": Excel sheet names cannot start or end with an apostrophe.',
                $name,
            ));
        }
    }

    /**
     * @param list<string> $existingNames
     */
    private function assertNameAvailable(string $name, array $existingNames): void
    {
        $lowerName = mb_strtolower($name, 'UTF-8');

        foreach ($existingNames as $existingName) {
            if (mb_strtolower($existingName, 'UTF-8') === $lowerName) {
                throw new InvalidOptionException(\sprintf(
                    'Cannot create sheet "%s": the workbook already contains "%s"; '
                    .'Excel sheet names are case-insensitive.',
                    $name,
                    $existingName,
                ));
            }
        }
    }

    /**
     * @return array{int, list<string>}
     */
    private function sheetMetadata(string $workbook): array
    {
        $dom = $this->parseXml($workbook, self::WORKBOOK_ENTRY);
        $maxSheetId = 0;
        $names = [];

        foreach ($dom->getElementsByTagNameNS(self::MAIN_NS, 'sheet') as $sheet) {
            $names[] = $sheet->getAttribute('name');
            $sheetId = $sheet->getAttribute('sheetId');

            if (preg_match('/^\d+$/', $sheetId) === 1) {
                $maxSheetId = max($maxSheetId, (int) $sheetId);
            }
        }

        return [$maxSheetId, $names];
    }

    private function nextRelationshipId(string $relationships): string
    {
        preg_match_all('/\bId\s*=\s*(["\'])(.*?)\1/', $relationships, $matches);
        $ids = $matches[2];
        $used = array_fill_keys($ids, true);
        $maxId = 0;

        foreach ($ids as $id) {
            if (preg_match('/^rId(\d+)$/', $id, $match) === 1) {
                $maxId = max($maxId, (int) $match[1]);
            }
        }

        do {
            $maxId++;
            $candidate = 'rId'.$maxId;
        } while (isset($used[$candidate]));

        return $candidate;
    }

    private function nextWorksheetPart(): string
    {
        $existing = [];
        $maxIndex = 0;

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $entry = $this->zip->getNameIndex($index);

            if ($entry === false) {
                continue;
            }

            $existing[strtolower($entry)] = true;

            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#i', $entry, $match) === 1) {
                $maxIndex = max($maxIndex, (int) $match[1]);
            }
        }

        do {
            $maxIndex++;
            $part = \sprintf('xl/worksheets/sheet%d.xml', $maxIndex);
        } while (isset($existing[strtolower($part)]));

        return $part;
    }

    /**
     * Finds the namespace prefix bound to the officeDocument relationships
     * namespace, adding it to the workbook root when it is not declared.
     *
     * @return array{string, string} prefix and possibly patched workbook XML
     */
    private function relationshipPrefix(string $workbook): array
    {
        if (preg_match('/<workbook\b[^>]*>/s', $workbook, $root) !== 1) {
            throw new InvalidWorkbookException(\sprintf(
                'The part "%s" in "%s" has no <workbook> root element; the file is not a valid XLSX workbook.',
                self::WORKBOOK_ENTRY,
                $this->path,
            ));
        }

        $namespace = preg_quote(self::DOCUMENT_REL_NS, '/');

        if (preg_match(
            '/xmlns:([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*(["\'])' . $namespace . '\2/',
            $root[0],
            $match,
        ) === 1) {
            return [$match[1], $workbook];
        }

        $prefix = 'r';
        $suffix = 0;

        while (preg_match('/\bxmlns:'.$prefix.'\s*=/', $root[0]) === 1) {
            $suffix++;
            $prefix = 'r'.$suffix;
        }

        $patched = preg_replace(
            '/<workbook\b/',
            '<workbook xmlns:'.$prefix.'="'.self::DOCUMENT_REL_NS.'"',
            $workbook,
            1,
        );

        if ($patched === null || $patched === $workbook) {
            throw new InvalidWorkbookException(\sprintf(
                'The part "%s" in "%s" has no <workbook> root element; the file is not a valid XLSX workbook.',
                self::WORKBOOK_ENTRY,
                $this->path,
            ));
        }

        return [$prefix, $patched];
    }

    private function insertSheet(
        string $workbook,
        string $name,
        int $sheetId,
        string $relationshipPrefix,
        string $relationshipId,
    ): string {
        $insert = \sprintf(
            '<sheet name="%s" sheetId="%d" %s:id="%s"/>',
            htmlspecialchars($name, \ENT_QUOTES | \ENT_XML1, 'UTF-8'),
            $sheetId,
            $relationshipPrefix,
            $relationshipId,
        );

        return self::insertIntoElement($workbook, 'sheets', $insert, self::WORKBOOK_ENTRY);
    }

    private static function insertIntoElement(string $xml, string $element, string $insert, string $entry): string
    {
        $closing = '</'.$element.'>';
        $position = strrpos($xml, $closing);

        if ($position !== false) {
            return substr($xml, 0, $position).$insert.substr($xml, $position);
        }

        if (preg_match(
            '/<'.preg_quote($element, '/').'(?:\s[^>]*)?\/>/',
            $xml,
            $match,
            \PREG_OFFSET_CAPTURE,
        ) === 1) {
            $tag = $match[0][0];
            $offset = $match[0][1];
            $attributes = substr($tag, \strlen('<'.$element), -2);
            $replacement = '<'.$element.$attributes.'>'.$insert.'</'.$element.'>';

            return substr($xml, 0, $offset).$replacement.substr($xml, $offset + \strlen($tag));
        }

        throw new InvalidWorkbookException(\sprintf(
            'The part "%s" has no closing %s tag or self-closing <%s/> element; the file is not a usable workbook.',
            $entry,
            $closing,
            $element,
        ));
    }

    private function readEntry(string $entry): string
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

    private static function emptyWorksheetXml(): string
    {
        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="%s" xmlns:r="%s"><dimension ref="A1:A1"/><sheetData/></worksheet>',
            self::MAIN_NS,
            self::DOCUMENT_REL_NS,
        );
    }
}
