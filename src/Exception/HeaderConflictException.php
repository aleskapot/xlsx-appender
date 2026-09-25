<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * write_header was requested for a sheet that already contains rows (C23).
 */
final class HeaderConflictException extends AppenderException
{
}
