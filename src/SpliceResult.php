<?php

declare(strict_types=1);

namespace XlsxFastAppender;

/**
 * Outcome of a splice run.
 */
final class SpliceResult
{
    public function __construct(
        public readonly int $rowsWritten,
        public readonly bool $headerWritten,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rowsWritten === 0 && !$this->headerWritten;
    }
}
