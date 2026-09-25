<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * The target archive is larger than 4 GB (zip64); v1 documents this as an
 * unsupported configuration and fails fast with a clear error (C20).
 */
final class UnsupportedArchiveException extends AppenderException
{
}
