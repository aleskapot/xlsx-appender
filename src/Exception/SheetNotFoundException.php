<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * The requested worksheet does not exist in the workbook (C5).
 */
final class SheetNotFoundException extends AppenderException
{
    /**
     * @var list<string> names of the sheets that do exist in the workbook
     */
    public readonly array $availableSheets;

    /**
     * @param list<string> $availableSheets
     */
    public function __construct(string $requested, array $availableSheets)
    {
        $this->availableSheets = $availableSheets;

        parent::__construct(\sprintf(
            'Sheet "%s" not found in workbook. Available sheets: %s.',
            $requested,
            $availableSheets === [] ? '(none)' : implode(', ', $availableSheets),
        ));
    }
}
