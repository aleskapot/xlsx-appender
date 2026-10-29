<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidWorkbookException;

/**
 * Resolves an Excel number format (numFmt) to a cellXfs style index for the
 * date cells of one append run (§3.4 of the specification).
 *
 * The existing xl/styles.xml is preserved byte-for-byte outside the spliced
 * elements: the numFmt is inserted into <numFmts> (creating the container
 * before <fonts> when the part has none) and a plain <xf> referencing it is
 * appended to <cellXfs>, with both count attributes refreshed. A format that
 * is already present — with a matching default-style xf — is reused without
 * touching the part at all.
 *
 * When the workbook has no styles part, a minimal valid styleSheet is built
 * and the caller registers it through contentTypesXml()/relationshipsXml().
 */
final class StylesStore
{
    public const STYLES_ENTRY = 'xl/styles.xml';

    public const CONTENT_TYPES_ENTRY = '[Content_Types].xml';

    public const RELATIONSHIPS_ENTRY = 'xl/_rels/workbook.xml.rels';

    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml';

    private const RELATIONSHIP_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';

    /**
     * Custom numFmt ids start at 164; 0..163 are reserved for built-ins.
     */
    private const FIRST_CUSTOM_NUM_FMT_ID = 164;

    /**
     * <numFmt>/<xf> open tag: quoted attribute values may contain `>`, so the
     * scan skips over quotes instead of stopping at the first `>`.
     */
    private const TAG = '#<%s\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#';

    private ?string $originalXml;

    private readonly bool $fileExisted;

    private ?int $dateStyle = null;

    private bool $changed = false;

    private function __construct(bool $fileExisted, ?string $originalXml)
    {
        $this->fileExisted = $fileExisted;
        $this->originalXml = $originalXml;
    }

    /**
     * Loads an existing xl/styles.xml part.
     *
     * @throws InvalidWorkbookException when the part is empty
     */
    public static function fromXml(string $stylesXml): self
    {
        if ($stylesXml === '') {
            throw new InvalidWorkbookException('The styles part is empty; the file is not a usable workbook.');
        }

        return new self(true, $stylesXml);
    }

    /**
     * Store for a workbook that has no xl/styles.xml yet.
     */
    public static function withoutFile(): self
    {
        return new self(false, null);
    }

    public function hasChanges(): bool
    {
        return $this->changed;
    }

    public function stylesFileExisted(): bool
    {
        return $this->fileExisted;
    }

    /**
     * Returns the <cellXfs> index of the style rendering $formatCode,
     * creating the numFmt/xf entries (or the whole part) on first use.
     *
     * @throws InvalidWorkbookException when the existing part has no <cellXfs>
     */
    public function dateStyleIndex(string $formatCode): int
    {
        if ($this->dateStyle !== null) {
            return $this->dateStyle;
        }

        if ($this->originalXml === null) {
            $this->originalXml = self::freshStylesXml($formatCode);
            $this->dateStyle = 1;
            $this->changed = true;

            return $this->dateStyle;
        }

        $numFmtId = self::findNumFmtId($this->originalXml, $formatCode);

        if ($numFmtId === null) {
            $numFmtId = self::nextNumFmtId($this->originalXml);
            $this->originalXml = self::insertNumFmt($this->originalXml, $numFmtId, $formatCode);
            $this->changed = true;
        }

        $styleIndex = self::findXfIndex($this->originalXml, $numFmtId);

        if ($styleIndex === null) {
            [$styleIndex, $xml] = self::appendXf($this->originalXml, $numFmtId);
            $this->originalXml = self::setCount($xml, 'cellXfs', $styleIndex + 1);
            $this->changed = true;
        }

        $this->dateStyle = $styleIndex;

        return $this->dateStyle;
    }

    /**
     * Assembles the complete new xl/styles.xml content.
     *
     * @throws InvalidWorkbookException when called before dateStyleIndex()
     */
    public function buildStylesXml(): string
    {
        if ($this->originalXml === null) {
            throw new InvalidWorkbookException(
                'The styles part has no content; dateStyleIndex() must run before buildStylesXml().',
            );
        }

        return $this->originalXml;
    }

    /**
     * Adds the Override registration for xl/styles.xml when the content types
     * do not know the part yet.
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
            '<Override PartName="/xl/styles.xml" ContentType="%s"/>',
            self::CONTENT_TYPE,
        );

        return self::insertBeforeClosing($currentXml, '</Types>', $insert, self::CONTENT_TYPES_ENTRY);
    }

    /**
     * Adds the workbook relationship for xl/styles.xml when it is missing;
     * an unused rIdN identifier is chosen.
     *
     *
     * @throws InvalidWorkbookException when xl/_rels/workbook.xml.rels has no closing tag
     *
     * @return string|null patched XML, or null when the relationship already exists
     */
    public function relationshipsXml(string $currentXml): ?string
    {
        if (preg_match('/Type="[^"]*\/styles"/', $currentXml) === 1) {
            return null;
        }

        $maxId = 0;
        preg_match_all('/Id="rId(\d+)"/', $currentXml, $ids);

        foreach ($ids[1] as $id) {
            $maxId = max($maxId, (int) $id);
        }

        $insert = \sprintf(
            '<Relationship Id="rId%d" Type="%s" Target="styles.xml"/>',
            $maxId + 1,
            self::RELATIONSHIP_TYPE,
        );

        return self::insertBeforeClosing($currentXml, '</Relationships>', $insert, self::RELATIONSHIPS_ENTRY);
    }

    private static function findNumFmtId(string $xml, string $formatCode): ?int
    {
        preg_match_all(\sprintf(self::TAG, 'numFmt'), $xml, $matches);

        foreach ($matches[0] as $tag) {
            if (preg_match('/\bformatCode="([^"]*)"/', $tag, $code) !== 1) {
                continue;
            }

            if (htmlspecialchars_decode($code[1], \ENT_QUOTES) !== $formatCode) {
                continue;
            }

            if (preg_match('/\bnumFmtId="(\d+)"/', $tag, $id) === 1) {
                return (int) $id[1];
            }
        }

        return null;
    }

    private static function nextNumFmtId(string $xml): int
    {
        $max = 0;

        if (preg_match('/<numFmts\b[^>]*>(.*?)<\/numFmts>/s', $xml, $segment) === 1) {
            preg_match_all('/\bnumFmtId="(\d+)"/', $segment[1], $ids);

            foreach ($ids[1] as $id) {
                $max = max($max, (int) $id);
            }
        }

        return max($max, self::FIRST_CUSTOM_NUM_FMT_ID - 1) + 1;
    }

    private static function insertNumFmt(string $xml, int $numFmtId, string $formatCode): string
    {
        $fmt = \sprintf(
            '<numFmt numFmtId="%d" formatCode="%s"/>',
            $numFmtId,
            htmlspecialchars($formatCode, \ENT_QUOTES | \ENT_XML1, 'UTF-8'),
        );

        $hasElement = preg_match('/<numFmts\b[^>]*>/', $xml) === 1
            || preg_match('/<numFmts\b[^>]*\/>/', $xml) === 1;

        if ($hasElement) {
            $xml = self::insertIntoElement($xml, 'numFmts', $fmt);
            $count = preg_match_all(\sprintf(self::TAG, 'numFmt'), self::elementBody($xml, 'numFmts'));

            return self::setCount($xml, 'numFmts', (int) $count);
        }

        $block = '<numFmts count="1">'.$fmt.'</numFmts>';

        if (preg_match('/<fonts\b/', $xml, $fonts, \PREG_OFFSET_CAPTURE) === 1) {
            $position = $fonts[0][1];
        } elseif (($position = strrpos($xml, '</styleSheet>')) !== false) {
            // Degenerate part without <fonts>: fall back to the root's end so
            // the container still lands before cellXfs in document order.
        } else {
            throw new InvalidWorkbookException(
                'The styles part has no <styleSheet> root element; the file is not a usable workbook.',
            );
        }

        return substr($xml, 0, $position).$block.substr($xml, $position);
    }

    /**
     * Finds a reusable <xf>: it must reference $numFmtId with every other
     * style component at its default (0) and not disable number formats.
     */
    private static function findXfIndex(string $xml, int $numFmtId): ?int
    {
        if (preg_match('/<cellXfs\b[^>]*>(.*?)<\/cellXfs>/s', $xml, $segment) !== 1) {
            return null;
        }

        preg_match_all(\sprintf(self::TAG, 'xf'), $segment[1], $matches);
        $index = 0;

        foreach ($matches[0] as $tag) {
            if (self::xfUsesOnlyFormat($tag, $numFmtId)) {
                return $index;
            }

            $index++;
        }

        return null;
    }

    private static function xfUsesOnlyFormat(string $tag, int $numFmtId): bool
    {
        if (preg_match('/\bnumFmtId="(\d+)"/', $tag, $id) !== 1 || (int) $id[1] !== $numFmtId) {
            return false;
        }

        if (preg_match('/\bapplyNumberFormat="(\d)"/', $tag, $apply) === 1 && $apply[1] === '0') {
            return false;
        }

        foreach (['fontId', 'fillId', 'borderId', 'xfId'] as $attribute) {
            if (preg_match('/\b'.$attribute.'="(\d+)"/', $tag, $value) === 1 && $value[1] !== '0') {
                return false;
            }
        }

        return true;
    }

    /**
     * Appends a plain date <xf> to <cellXfs>.
     *
     * @throws InvalidWorkbookException when the part has no <cellXfs> element
     *
     * @return array{int, string} 0-based index of the new xf and the patched XML
     */
    private static function appendXf(string $xml, int $numFmtId): array
    {
        if (preg_match('/<cellXfs\b[^>]*>(.*?)<\/cellXfs>/s', $xml, $segment) === 1) {
            $index = (int) preg_match_all(\sprintf(self::TAG, 'xf'), $segment[1]);
        } elseif (preg_match('/<cellXfs\b[^>]*\/>/', $xml) === 1) {
            $index = 0;
        } else {
            throw new InvalidWorkbookException(
                'The styles part has no <cellXfs> element; the file is not a usable workbook.',
            );
        }

        $xf = \sprintf(
            '<xf numFmtId="%d" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            $numFmtId,
        );

        return [$index, self::insertIntoElement($xml, 'cellXfs', $xf)];
    }

    /**
     * Inserts $insert before the closing tag of $element, expanding the
     * self-closed form; throws when the element is missing entirely.
     *
     * @throws InvalidWorkbookException when the element cannot be located
     */
    private static function insertIntoElement(string $xml, string $element, string $insert): string
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
            'The styles part has no closing %s tag or self-closing <%s/> element; the file is not a usable workbook.',
            $closing,
            $element,
        ));
    }

    /**
     * Returns the inner body of an element (its children), for counting.
     */
    private static function elementBody(string $xml, string $element): string
    {
        if (preg_match('/<'.preg_quote($element, '/').'\b[^>]*>(.*?)<\/'.preg_quote($element, '/').'>/s', $xml, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    /**
     * Rewrites (or adds) the count attribute of the element's open tag.
     */
    private static function setCount(string $xml, string $element, int $count): string
    {
        $result = preg_replace_callback(
            '/<'.preg_quote($element, '/').'\b([^>]*?)(\/?)>/',
            static function (array $match) use ($element, $count): string {
                $attributes = trim(preg_replace('/\s+count="\d+"/', '', $match[1]) ?? '');
                $attributes = ($attributes === '' ? '' : ' '.$attributes).' count="'.$count.'"';

                return '<'.$element.$attributes.($match[2] === '/' ? '/' : '').'>';
            },
            $xml,
            1,
        );

        return $result ?? $xml;
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

    /**
     * Minimal styleSheet that is valid on its own: one default font, the two
     * mandatory fills, one default border, and the date style as xf index 1.
     */
    private static function freshStylesXml(string $formatCode): string
    {
        $formatCode = htmlspecialchars($formatCode, \ENT_QUOTES | \ENT_XML1, 'UTF-8');

        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="%s">'
            .'<numFmts count="1"><numFmt numFmtId="%d" formatCode="%s"/></numFmts>'
            .'<fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/>'
            .'</font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="%d" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>',
            self::MAIN_NS,
            self::FIRST_CUSTOM_NUM_FMT_ID,
            $formatCode,
            self::FIRST_CUSTOM_NUM_FMT_ID,
        );
    }
}
