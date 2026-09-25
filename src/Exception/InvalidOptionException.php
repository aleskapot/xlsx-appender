<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * An option passed to XlsxAppender is unknown, has the wrong type, or
 * carries a value this version does not support.
 */
final class InvalidOptionException extends AppenderException
{
}
