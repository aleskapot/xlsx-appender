<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model used by Laravel integration tests. Rows are built in memory
 * only — no database connection is ever opened.
 */
final class Report extends Model
{
    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];
}
