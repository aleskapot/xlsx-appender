<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Support;

use XlsxFastAppender\ColumnName;

/**
 * Builds minimal, standards-compliant XLSX files for tests.
 *
 * The produced workbook contains only the parts the package actually reads
 * ([Content_Types].xml, _rels/.rels, xl/workbook.xml, xl/_rels/workbook.xml.rels
 * and the worksheet parts) so tests stay fast and fully controllable.
 */
final class XlsxFixtureFactory
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const DOC_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const CONTENT_TYPES_NS = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /**
     * Creates an xlsx file at $path with the given sheets.
     *
     * @param array<string, string> $sheets      sheet name => worksheet XML (full <worksheet> element)
     * @param array<string, string> $extraEntries additional zip entries (e.g. an existing
     *                                            xl/sharedStrings.xml), overriding generated ones
     */
    public static function create(string $path, array $sheets, array $extraEntries = []): string
    {
        $entries = [
            '[Content_Types].xml' => self::contentTypes(array_keys($sheets)),
            '_rels/.rels' => self::rootRelationships(),
            'xl/workbook.xml' => self::workbookXml(array_keys($sheets)),
            'xl/_rels/workbook.xml.rels' => self::workbookRelationships(\count($sheets)),
        ];

        $index = 1;

        foreach ($sheets as $worksheetXml) {
            $entries[\sprintf('xl/worksheets/sheet%d.xml', $index)] = $worksheetXml;
            $index++;
        }

        foreach ($extraEntries as $entry => $contents) {
            $entries[$entry] = $contents;
        }

        return self::write($path, $entries);
    }

    /**
     * Writes arbitrary entries as a zip (used by tests that need broken or exotic parts).
     *
     * @param array<string, string> $entries zip entry path => contents
     */
    public static function write(string $path, array $entries): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(\sprintf('Unable to create fixture "%s".', $path));
        }

        foreach ($entries as $entry => $contents) {
            if ($zip->addFromString($entry, $contents) !== true) {
                throw new \RuntimeException(\sprintf('Unable to add entry "%s" to fixture "%s".', $entry, $path));
            }
        }

        if ($zip->close() !== true) {
            throw new \RuntimeException(\sprintf('Unable to save fixture "%s".', $path));
        }

        return $path;
    }

    /**
     * Standard worksheet with inline-string rows numbered from 1.
     *
     * @param list<list<string>> $rows
     */
    public static function worksheet(array $rows): string
    {
        $rowNumber = 1;
        $maxColumn = 1;
        $sheetData = '';

        foreach ($rows as $cells) {
            $sheetData .= \sprintf('<row r="%d">', $rowNumber);
            $column = 1;

            foreach ($cells as $value) {
                $sheetData .= \sprintf(
                    '<c r="%s%d" t="inlineStr"><is><t>%s</t></is></c>',
                    ColumnName::of($column),
                    $rowNumber,
                    htmlspecialchars($value, \ENT_QUOTES | \ENT_XML1, 'UTF-8'),
                );

                if ($column > $maxColumn) {
                    $maxColumn = $column;
                }

                $column++;
            }

            $sheetData .= '</row>';
            $rowNumber++;
        }

        $lastRow = $rowNumber - 1;
        $dimension = $lastRow === 0
            ? ''
            : \sprintf('<dimension ref="A1:%s%d"/>', ColumnName::of($maxColumn), $lastRow);

        return self::wrapWorksheet($dimension.$sheetData);
    }

    /**
     * Worksheet with an empty, self-closed <sheetData/> (C1). Like Excel's own
     * output for a brand-new sheet, it carries a one-cell <dimension>.
     */
    public static function emptyWorksheet(): string
    {
        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="%s" xmlns:r="%s"><dimension ref="A1:A1"/><sheetData/></worksheet>',
            self::MAIN_NS,
            self::DOC_REL_NS,
        );
    }

    /**
     * Wraps sheet body into a <worksheet> element. $afterSheetData is emitted
     * after </sheetData> to emulate generator-specific trailing elements (C17).
     */
    public static function wrapWorksheet(string $sheetData, string $beforeSheetData = '', string $afterSheetData = ''): string
    {
        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="%s" xmlns:r="%s">%s<sheetData>%s</sheetData>%s</worksheet>',
            self::MAIN_NS,
            self::DOC_REL_NS,
            $beforeSheetData,
            $sheetData,
            $afterSheetData,
        );
    }

    /**
     * @param list<string> $sheetNames
     */
    public static function workbookXml(array $sheetNames): string
    {
        $sheets = '';
        $sheetId = 1;

        foreach ($sheetNames as $name) {
            $sheets .= \sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                htmlspecialchars($name, \ENT_QUOTES | \ENT_XML1, 'UTF-8'),
                $sheetId,
                $sheetId,
            );
            $sheetId++;
        }

        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="%s" xmlns:r="%s"><sheets>%s</sheets><calcPr calcId="191028"/></workbook>',
            self::MAIN_NS,
            self::DOC_REL_NS,
            $sheets,
        );
    }

    public static function workbookRelationships(int $sheetCount): string
    {
        $relationships = '';

        for ($i = 1; $i <= $sheetCount; $i++) {
            $relationships .= \sprintf(
                '<Relationship Id="rId%d" Type="%s/worksheet" Target="worksheets/sheet%d.xml"/>',
                $i,
                self::DOC_REL_NS,
                $i,
            );
        }

        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="%s">%s</Relationships>',
            self::PACKAGE_REL_NS,
            $relationships,
        );
    }

    /**
     * @param list<string> $sheetNames
     */
    private static function contentTypes(array $sheetNames): string
    {
        $overrides = '';
        $sheetCount = \count($sheetNames);

        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= \sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" '
                .'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index,
            );
        }

        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="%s">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" '
            .'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'%s'
            .'</Types>',
            self::CONTENT_TYPES_NS,
            $overrides,
        );
    }

    private static function rootRelationships(): string
    {
        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="%s">'
            .'<Relationship Id="rId1" Type="%s/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>',
            self::PACKAGE_REL_NS,
            self::DOC_REL_NS,
        );
    }
}
