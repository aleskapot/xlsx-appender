<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * The advisory lock on the target file could not be acquired within
 * lock_timeout seconds — another process is appending (C12).
 */
final class LockTimeoutException extends AppenderException
{
}
