<?php

declare(strict_types=1);

namespace XlsxFastAppender;

/**
 * A validated date cell produced by RowXmlGenerator: the Excel serial number
 * plus the cellXfs style index whose number format renders it. Keeping dates
 * in a dedicated value lets the emission step tell them apart from plain
 * numbers and attach the `s` attribute.
 */
final readonly class DateCell
{
    public function __construct(
        public int|float $serial,
        public int       $style,
    ) {
    }
}
