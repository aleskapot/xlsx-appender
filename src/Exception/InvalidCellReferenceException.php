<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * A cell/column/row reference is syntactically invalid or outside the
 * limits of the Excel format (C18, C22, C26).
 */
final class InvalidCellReferenceException extends AppenderException
{
}
