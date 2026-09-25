<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidWorkbookException;

/**
 * In-memory deduplication index for the shared-strings part (§3.2).
 *
 * Existing `<si>` entries are loaded into a text => ordinal hash table, so an
 * append run only grows the table by strings that are not present yet;
 * memory is O(unique strings) — the documented trade-off of this mode.
 *
 * The rebuilt part keeps the original XML byte-for-byte and only splices the
 * new `<si>` fragments before `</sst>` plus the refreshed count/uniqueCount
 * attributes of the `<sst>` open tag.
 */
final class SharedStringsStore
{
    public const SST_ENTRY = 'xl/sharedStrings.xml';

    public const CONTENT_TYPES_ENTRY = '[Content_Types].xml';

    public const RELATIONSHIPS_ENTRY = 'xl/_rels/workbook.xml.rels';

    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml';

    private const RELATIONSHIP_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings';

    /**
     * text => ordinal position of its `<si>` element.
     *
     * @var array<string, int>
     */
    private array $indexes = [];

    private int $siCount = 0;

    private int $existingReferences;

    private int $newReferences = 0;

    /**
     * Serialized `<si>` fragments contributed by this run.
     *
     * @var list<string>
     */
    private array $pending = [];

    private ?string $originalXml;

    private bool $fileExisted;

    private function __construct(bool $fileExisted, ?string $originalXml, int $existingReferences)
    {
        $this->fileExisted = $fileExisted;
        $this->originalXml = $originalXml;
        $this->existingReferences = $existingReferences;
    }

    /**
     * Loads an existing xl/sharedStrings.xml part (C6/C7).
     *
     * @throws InvalidWorkbookException when the part is empty
     */
    public static function fromXml(string $sstXml): self
    {
        if ($sstXml === '') {
            throw new InvalidWorkbookException('The shared strings part is empty; the file is not a usable workbook.');
        }

        $store = new self(true, $sstXml, self::parseCountAttribute($sstXml));
        $store->load($sstXml);

        return $store;
    }

    /**
     * Store for a workbook that has no xl/sharedStrings.xml yet (C6).
     */
    public static function withoutFile(): self
    {
        return new self(false, null, 0);
    }

    /**
     * Returns the `<si>` index for a string, appending a new entry when the
     * text is not known yet. Every call counts as one shared-string reference.
     */
    public function index(string $value): int
    {
        $this->newReferences++;

        $existing = $this->indexes[$value] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        $index = $this->siCount;
        $this->siCount++;
        $this->indexes[$value] = $index;
        $this->pending[] = self::renderSi($value);

        return $index;
    }

    public function hasNewReferences(): bool
    {
        return $this->newReferences > 0;
    }

    public function sharedStringsFileExisted(): bool
    {
        return $this->fileExisted;
    }

    /**
     * Assembles the complete new xl/sharedStrings.xml content: original XML
     * with the new `<si>` fragments spliced before `</sst>` and refreshed
     * count (existing references + this run) / uniqueCount (actual `<si>`
     * total, recalculated — C7) attributes.
     *
     * @throws InvalidWorkbookException when the existing part has neither `</sst>` nor a self-closed `<sst/>`
     */
    public function buildSstXml(): string
    {
        $count = $this->existingReferences + $this->newReferences;
        $uniqueCount = $this->siCount;

        if ($this->originalXml === null) {
            return \sprintf(
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<sst xmlns="%s" count="%d" uniqueCount="%d">%s</sst>',
                self::MAIN_NS,
                $count,
                $uniqueCount,
                implode('', $this->pending),
            );
        }

        $closing = strpos($this->originalXml, '</sst>');

        if ($closing !== false) {
            $prefix = substr($this->originalXml, 0, $closing);
            $prefix = preg_replace_callback(
                '/<sst\b[^>]*>/',
                static fn (array $match): string => self::rewriteOpenTag($match[0], $count, $uniqueCount),
                $prefix,
                1,
            ) ?? $prefix;

            return $prefix.implode('', $this->pending).substr($this->originalXml, $closing);
        }

        $rewritten = preg_replace_callback(
            '/<sst\b[^>]*\/>/',
            static fn (array $match): string => self::rewriteOpenTag($match[0], $count, $uniqueCount),
            $this->originalXml,
            1,
            $countMatch,
        );

        if ($countMatch !== 1 || $rewritten === null) {
            throw new InvalidWorkbookException(
                'The shared strings part has no closing </sst> tag; the file is not a usable workbook.',
            );
        }

        return $rewritten.implode('', $this->pending).'</sst>';
    }

    /**
     * Adds the Override registration for xl/sharedStrings.xml when the
     * content types do not know the part yet (C6).
     *
     *
     * @throws InvalidWorkbookException when [Content_Types].xml has no closing tag
     *
     * @return string|null patched XML, or null when the registration already exists
     */
    public function contentTypesXml(string $currentXml): ?string
    {
        if (str_contains($currentXml, self::CONTENT_TYPE)) {
            return null;
        }

        $insert = \sprintf(
            '<Override PartName="/xl/sharedStrings.xml" ContentType="%s"/>',
            self::CONTENT_TYPE,
        );

        return self::insertBeforeClosing($currentXml, '</Types>', $insert, '[Content_Types].xml');
    }

    /**
     * Adds the workbook relationship for xl/sharedStrings.xml when it is
     * missing (C6); an unused rIdN identifier is chosen.
     *
     *
     * @throws InvalidWorkbookException when xl/_rels/workbook.xml.rels has no closing tag
     *
     * @return string|null patched XML, or null when the relationship already exists
     */
    public function relationshipsXml(string $currentXml): ?string
    {
        if (preg_match('/Type="[^"]*\/sharedStrings"/', $currentXml) === 1) {
            return null;
        }

        $maxId = 0;
        preg_match_all('/Id="rId(\d+)"/', $currentXml, $ids);

        foreach ($ids[1] as $id) {
            $maxId = max($maxId, (int) $id);
        }

        $insert = \sprintf(
            '<Relationship Id="rId%d" Type="%s" Target="sharedStrings.xml"/>',
            $maxId + 1,
            self::RELATIONSHIP_TYPE,
        );

        return self::insertBeforeClosing($currentXml, '</Relationships>', $insert, 'xl/_rels/workbook.xml.rels');
    }

    /**
     * Streams every `<si>` element into the text => index map; phonetic `rPh`
     * runs are ignored because they are not part of the visible string.
     */
    private function load(string $sstXml): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $reader = \XMLReader::XML($sstXml, null, \LIBXML_NONET);

            if (!$reader instanceof \XMLReader) {
                throw new InvalidWorkbookException('The shared strings part is not well-formed.');
            }

            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'si') {
                    continue;
                }

                $text = $reader->isEmptyElement ? '' : $this->readSiText($reader);

                if (!isset($this->indexes[$text])) {
                    $this->indexes[$text] = $this->siCount;
                }

                $this->siCount++;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function readSiText(\XMLReader $reader): string
    {
        $text = '';
        $insidePhonetic = 0;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($reader->localName === 'rPh') {
                    if (!$reader->isEmptyElement) {
                        $insidePhonetic++;
                    }

                    continue;
                }

                if ($insidePhonetic === 0 && $reader->localName === 't') {
                    $text .= $reader->readString();
                }

                continue;
            }

            if ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->localName === 'rPh') {
                    $insidePhonetic--;

                    continue;
                }

                if ($reader->localName === 'si') {
                    break;
                }
            }
        }

        return $text;
    }

    private static function parseCountAttribute(string $sstXml): int
    {
        if (preg_match('/<sst\b[^>]*?\scount="(\d+)"/', $sstXml, $match) !== 1) {
            return 0;
        }

        return (int) $match[1];
    }

    /**
     * Rebuilds the `<sst ...>` open tag with the refreshed count attributes,
     * preserving everything else (namespace, other attributes, order).
     */
    private static function rewriteOpenTag(string $tag, int $count, int $uniqueCount): string
    {
        $attributes = preg_replace('/^<sst\b/', '', $tag) ?? '';
        $attributes = preg_replace('/\/?>$/', '', $attributes) ?? '';
        $attributes = trim(preg_replace('/\s+(?:count|uniqueCount)="[^"]*"/', '', $attributes) ?? '');

        return \sprintf(
            '<sst%s count="%d" uniqueCount="%d">',
            $attributes === '' ? '' : ' '.$attributes,
            $count,
            $uniqueCount,
        );
    }

    private static function insertBeforeClosing(string $xml, string $closing, string $insert, string $entry): string
    {
        $position = strrpos($xml, $closing);

        if ($position === false) {
            throw new InvalidWorkbookException(\sprintf(
                'The part "%s" has no closing %s tag; the file is not a usable workbook.',
                $entry,
                $closing,
            ));
        }

        return substr($xml, 0, $position).$insert.substr($xml, $position);
    }

    private static function renderSi(string $value): string
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(false);
        $writer->startElement('si');
        $writer->startElement('t');

        if ($value !== trim($value)) {
            $writer->writeAttribute('xml:space', 'preserve');
        }

        $writer->text($value);
        $writer->endElement();
        $writer->endElement();

        return $writer->outputMemory();
    }
}
