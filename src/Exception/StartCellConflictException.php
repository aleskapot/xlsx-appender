<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * The requested start cell lies inside or above existing data (C2, C3):
 * rows can only be appended below the last existing row.
 */
final class StartCellConflictException extends AppenderException
{
}
