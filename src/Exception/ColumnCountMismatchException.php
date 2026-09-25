<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * A record does not match the configured `columns`: positional arity differs
 * from the column count, or `strict_columns` found unexpected keys (C24, C25).
 */
final class ColumnCountMismatchException extends AppenderException
{
}
