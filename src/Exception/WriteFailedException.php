<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * The target file or its directory cannot be written to, or a temporary
 * file / zip write failed (C13). The original file is left unchanged.
 */
final class WriteFailedException extends AppenderException
{
}
