<?php

declare(strict_types=1);

namespace XlsxFastAppender;

/**
 * Splits the inner XML of <sheetData> for the "clear" and "overwrite"
 * conflict modes: rows are classified as kept-before (r < startRow),
 * kept-after (r above the replaced block) or dropped.
 *
 * Row numbers follow SheetDataScanner::walk(): a missing or non-digit "r"
 * attribute continues the previous row number + 1. Kept rows keep their
 * original bytes; the whitespace between elements travels with the element
 * that follows it.
 *
 * @internal
 */
final class SheetRowPartitioner
{
    private const ROW_OPEN = '/<row\b[^>]*>/';

    private const ROW_NUMBER = '/\br="(\d+)"/';

    private const ROW_CLOSE = '</row>';

    private function __construct()
    {
    }

    /**
     * @param int|null $lastReplacedRow inclusive upper bound of the replaced
     *                                  range, or null to drop every row from
     *                                  $startRow to the end of the sheet (clear)
     *
     * @return array{before: string, after: string, dropped: int, keptMaxRow: int}
     */
    public static function partition(string $body, int $startRow, ?int $lastReplacedRow): array
    {
        $before = '';
        $after = '';
        $dropped = 0;
        $keptMaxRow = 0;
        $previousRow = 0;
        $cursor = 0;

        while (preg_match(self::ROW_OPEN, $body, $match, \PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $tagStart = $match[0][1];
            $tag = $match[0][0];
            $elementEnd = self::elementEnd($body, $tagStart, $tag);
            $gap = substr($body, $cursor, $tagStart - $cursor);

            $previousRow = preg_match(self::ROW_NUMBER, $tag, $number) === 1
                ? (int) $number[1]
                : $previousRow + 1;

            $replaced = $previousRow >= $startRow
                && ($lastReplacedRow === null || $previousRow <= $lastReplacedRow);

            if ($replaced) {
                $dropped++;
                $before .= $gap;
            } else {
                $chunk = $gap.substr($body, $tagStart, $elementEnd - $tagStart);

                if ($previousRow < $startRow) {
                    $before .= $chunk;
                } else {
                    $after .= $chunk;
                }

                if ($previousRow > $keptMaxRow) {
                    $keptMaxRow = $previousRow;
                }
            }

            $cursor = $elementEnd;
        }

        return [
            'before' => $before,
            'after' => $after.substr($body, $cursor),
            'dropped' => $dropped,
            'keptMaxRow' => $keptMaxRow,
        ];
    }

    /**
     * Offset just past the row element that starts at $tagStart: self-closed
     * rows end with the open tag, regular rows with the next </row>. When the
     * close tag is missing the element swallows the rest of the body so the
     * assembled XML stays malformed and the verification step rejects it.
     */
    private static function elementEnd(string $body, int $tagStart, string $tag): int
    {
        if (str_ends_with($tag, '/>')) {
            return $tagStart + \strlen($tag);
        }

        $close = strpos($body, self::ROW_CLOSE, $tagStart + \strlen($tag));

        return $close === false ? \strlen($body) : $close + \strlen(self::ROW_CLOSE);
    }
}
