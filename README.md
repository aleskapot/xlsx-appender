# xlsx-fast-appender

Fast, low-memory appending of rows into an **existing** XLSX sheet — without
re-saving the workbook. Built on `ZipArchive` + streaming `XMLWriter`
(specification variant C), it touches only the worksheet part, keeps every other
zip entry byte-identical, and leaves the file untouched on any failure.

```text
open → resolve sheet → read worksheet XML → scan rows → validate →
splice new rows → verify → atomic save
```

## Requirements

- PHP >= 8.2 with `ext-zip`, `ext-dom`, `ext-xml`, `ext-xmlreader`, `ext-xmlwriter`, `ext-mbstring`

## Installation

```bash
composer require aleskapot/xlsx-fast-appender
```

## Quick start

```php
use XlsxFastAppender\XlsxAppender;

$written = (new XlsxAppender(path: '/data/report.xlsx', options: [
    'sheet'      => 'Data',     // null = first sheet
    'start_cell' => 'A2',       // first free row for the batch
]))->append($rows);             // iterable of row arrays, returns count written
```

Rows are `list` arrays (positional) or associative arrays/objects when
`columns` is configured:

```php
$appender = new XlsxAppender('/data/report.xlsx', [
    'sheet'        => 'Data',
    'start_cell'   => 'A2',
    'columns'      => ['name' => 'Name', 'amount' => 'Amount'], // key => header label
    'write_header' => true,       // header row once, on an empty sheet
]);

$appender->append([
    ['name' => 'Ada',   'amount' => 10],
    ['name' => 'Grace', 'amount' => 20],
]);

// or lazily, without materialising anything:
$appender->append(Report::query()->lazy());          // Eloquent
$appender->append((static function (): Generator {   // plain generator
    yield [1, 'first'];
    yield [2, 'second'];
})());
```

A second `append()` on the same file re-scans it, so consecutive batches just
need a `start_cell` below existing data:

```php
(new XlsxAppender('/data/report.xlsx', ['start_cell' => 'A4']))->append($more);
```

## Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `sheet` | `string\|null` | `null` | Sheet name; `null` selects the first sheet. Unknown name → `SheetNotFoundException` with the list of available names. |
| `start_cell` | `string` | `'A1'` | Top-left cell for the first data row (e.g. `'A2'` under a header row). Must not intersect existing rows (C2) unless `conflict_mode` says otherwise. |
| `conflict_mode` | `string\|ConflictMode` | `'error'` | What to do when `start_cell` points into existing rows: `'error'` throws `StartCellConflictException`, `'clear'` drops every row from `start_cell` to the end of the sheet, `'overwrite'` replaces only the rows the new batch covers. |
| `mode` | `string\|StringMode` | `'inline_str'` | `'inline_str'` (strings embedded in cells) or `'shared_strings'` (shared `xl/sharedStrings.xml`, deduplicated). |
| `max_sheet_xml_size` | `int` | `268435456` (256 MiB) | Upper bound for the worksheet XML part read into memory (C11). |
| `use_lock` | `bool` | `true` | Serialise writers through a sidecar `<file>.lock` with `flock` (C12). |
| `lock_timeout` | `float` | `30.0` | Seconds to wait for the lock before `LockTimeoutException`. |
| `skip_nulls` | `bool` | `true` | Skip `null` values (empty cells) instead of writing empty ones (C16). |
| `columns` | `array\|null` | `null` | `list<string>` of keys, or `array<string,string>` key => header label. Enables key-based mapping for associative arrays/objects. |
| `write_header` | `bool` | `false` | Write the header row once at `start_cell − 1`; requires `columns` and an empty sheet (C21–C23). |
| `strict_columns` | `bool` | `false` | Reject records containing keys outside `columns` (C24). |

Unknown options and type violations throw `InvalidOptionException` at
construction time.

### Enum options

`conflict_mode` and `mode` accept their string values or the matching backed
enums — handy for IDE autocompletion and safe refactoring:

```php
use XlsxFastAppender\ConflictMode;
use XlsxFastAppender\StringMode;

(new XlsxAppender('/data/report.xlsx', [
    'conflict_mode' => ConflictMode::Clear,        // === 'clear'
    'mode'          => StringMode::SharedStrings,  // === 'shared_strings'
]))->append($rows);
```

`ConflictMode` carries `Error` / `Clear` / `Overwrite`, `StringMode` carries
`InlineStr` / `SharedStrings`; the published Laravel config accepts both forms
too. Any other value — a different enum, an unknown string, a wrong type —
throws `InvalidOptionException`.

### Conflict modes

By default a `start_cell` that points into existing rows throws
`StartCellConflictException` — rows can only be appended below the last
existing row. `conflict_mode` selects two rewrite behaviours instead:

- `'clear'` — delete every existing row from `start_cell`'s row to the end of
  the sheet, then write the new batch there. Rows above `start_cell` are kept:

  ```php
  // sheet has rows 1..500; rows 1..9 stay, rows 10..500 are dropped,
  // then the batch is written at row 10:
  (new XlsxAppender($path, [
      'start_cell'    => 'A10',
      'conflict_mode' => 'clear',
  ]))->append($rows);
  ```

- `'overwrite'` — write the batch over the rows it covers and keep everything
  else: rows above `start_cell` are untouched, and rows below the last written
  row survive even when the new batch is shorter than the old block.

Both modes rewrite whole rows (cells left of `start_cell` in a replaced row go
away with it), keep the surrounding rows byte-identical and in ascending row
order, and are a no-op when the batch is empty — `append([])` returns `0`
without touching the file. Elements outside `<sheetData>` that reference
dropped rows (merged cells, conditional formatting ranges) are left as-is.
`write_header` still requires an empty sheet (C23), independent of the mode.

### Header mapping

```php
$appender = new XlsxAppender('/data/report.xlsx', [
    'start_cell'   => 'A2',
    'columns'      => ['name' => 'Имя', 'amount' => 'Сумма'],  // key => label
    'write_header' => true,
]);

// header written once at start_cell − 1, data rows from start_cell:
//   row 1:  Имя | Сумма      (labels, UTF-8)
//   row 2+: your data        (values looked up by key)

// positional records must match the column count exactly (C25):
['Ada', 10]  → ok with 2 columns
['Ada']      → ColumnCountMismatchException
```

`null` values are skipped by default (`skip_nulls=true`), so sparse associative
records align correctly. Objects (including Eloquent models) are read via
`getAttribute()` when they provide it, then public properties, then `__get`.

## Modes

- `inline_str` (default) — each string cell carries its text inline; simplest and
  fastest for appending.
- `shared_strings` — string cells reference `xl/sharedStrings.xml`
  (`t="s"`); new unique strings are appended to the existing part (rich text
  and phonetic runs are preserved), duplicate ordinals are reused and stale
  `count`/`uniqueCount` attributes are refreshed (C6, C7). The part and its
  `[Content_Types].xml` / `workbook.xml.rels` registrations are created on
  first use. Prefer it for heavily repeated strings.

## Laravel

### Installation

```bash
composer require aleskapot/xlsx-fast-appender
```

The service provider is auto-discovered (Laravel >= 5.5) through
`extra.laravel.providers` in `composer.json` — nothing else to register. With
package discovery disabled (`"dont-discover"` or Lumen), add it manually:

```php
// bootstrap/providers.php (Laravel >= 11)
return [
    // ...
    XlsxFastAppender\Laravel\XlsxFastAppenderServiceProvider::class,
];

// config/app.php (Laravel <= 10)
'providers' => [
    // ...
    XlsxFastAppender\Laravel\XlsxFastAppenderServiceProvider::class,
],
```

No facade alias is registered — import the facade by its full class name.

### Usage

```php
use XlsxFastAppender\Laravel\Facades\XlsxAppender;

$written = XlsxAppender::append('/data/report.xlsx', $rows, [
    'sheet'      => 'Data',
    'start_cell' => 'A2',
]);
```

`append()` is a shortcut for `make(...)->append(...)`; build a configured
instance when you need more than one batch:

```php
$appender = XlsxAppender::make('/data/report.xlsx', [
    'columns'      => ['name' => 'Name', 'amount' => 'Amount'],
    'write_header' => true,
]);

$appender->append(Report::query()->lazy(500));   // streams from Eloquent
```

### Configuration

Defaults come from the published config (`start_cell=A2`, `conflict_mode=error`,
`mode=inline_str`, `use_lock=true`, …):

```bash
php artisan vendor:publish --tag=xlsx-appender
```

`config/xlsx-appender.php` accepts exactly the options from the table above;
per-call overrides win over config. The class binding is a factory, never a
singleton:

```php
$appender = app(\XlsxFastAppender\XlsxAppender::class, [
    'path'    => '/data/report.xlsx',
    'options' => ['start_cell' => 'C3'],   // optional, merged over config
]);
```

### Queue job pattern

One worker per file is the recommendation; `flock` serialises the rest:

```php
final class AppendReportRows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(private readonly string $path) {}

    public function handle(): void
    {
        XlsxAppender::append(
            $this->path,
            Report::query()->lazy(500),     // streams, never loads all rows
            ['sheet' => 'Data', 'start_cell' => 'A2'],
        );
    }
}
```

For batches, start each subsequent append below the rows already written
(`start_cell` = last written row + 1), or chunk manually:

```php
foreach ($reports->chunk(1000) as $i => $chunk) {
    XlsxAppender::append($path, $chunk, ['start_cell' => 'A'.(2 + $i * 1000)]);
}
```

## Exceptions

All exceptions extend `XlsxFastAppender\Exception\AppenderException`.

| Exception | When |
|-----------|------|
| `InvalidOptionException` | Unknown/invalid option, or invalid container `path`/`options` binding parameters. |
| `InvalidCellReferenceException` | `start_cell` unparseable or out of Excel limits (C18); column overflow (C26); `write_header` at row 1 (C22). |
| `StartCellConflictException` | `start_cell` intersects existing rows or a row gap (C2, C3); only with `conflict_mode='error'` — `'clear'`/`'overwrite'` rewrite instead. |
| `HeaderConflictException` | `write_header` on a sheet that already has rows (C23). |
| `SheetNotFoundException` | Sheet name not found; carries `availableSheets` (C5). |
| `InvalidWorkbookException` | Not a valid XLSX: missing/malformed workbook, rels, worksheet, or `sharedStrings.xml` (C14). |
| `ColumnCountMismatchException` | Positional record width ≠ column count, or extra keys with `strict_columns` (C24, C25). |
| `UnsupportedValueException` | Cell value type not supported (DateTime, arrays, objects without `map()`…). |
| `AppenderSizeLimitException` | Worksheet or shared-strings part exceeds `max_sheet_xml_size` (C11). |
| `UnsupportedArchiveException` | Archive larger than 4 GB — v1 writes zip32 only (C20). |
| `LockTimeoutException` | Lock held by another process longer than `lock_timeout` (C12). |
| `WriteFailedException` | Temporary file, permission or zip write/close failure. |

On any exception the original file is left **byte-identical**: entries are
added to an uncommitted archive state and rolled back (`unchangeAll()`), temp
files are removed, and the lock is released in `finally`.

## Limits

- String cell: 32 767 characters (longer → `UnsupportedValueException`, C9).
- Columns: `A` … `XFD` (16 384); rows: 1 … 1 048 576.
- Worksheet part read into memory: `max_sheet_xml_size` (default 256 MiB, C11).
- Archive: ≤ 4 GB (zip32) in v1; larger files fail fast with
  `UnsupportedArchiveException` instead of corrupting the zip (C20).
- Characters forbidden in XML (`\x00`, `\x01`, …) are rejected with the cell
  address in the message.

## Atomicity and locking

- The worksheet is spliced into a temp file, verified (parsed again), then the
  archive entry is replaced and saved in a single `close()`. Any failure —
  including an iterator throwing halfway — leaves the original bytes intact.
- `use_lock=true` (default) takes a non-blocking `flock(LOCK_EX)` on a
  **sidecar** `<file>.lock`, polled every 50 ms up to `lock_timeout`. A sidecar
  (rather than the target file itself) is required because holding `flock` on
  the `.xlsx` blocks `ZipArchive::open()` for writing on win32. The sidecar is
  created once and never deleted (removing it while another process polls would
  split the lock across two inodes).
- Use one writer process per file when possible; the lock only serialises, it
  does not queue.

## Trade-offs

Variant C (this package) reads the worksheet XML, splices and rewrites it:

- **+** No external dependencies; works wherever `ext-zip` works; every
  untouched zip entry stays byte-identical (pass-through preserves
  generator-specific markup, C17).
- **+** Memory is proportional to the worksheet XML, not to PhpSpreadsheet's
  object graph — see benchmark below.
- **−** A single worksheet must fit into `max_sheet_xml_size` (default
  256 MiB). For larger sheets, migrate the read/splice layer to an unzipping
  pipe or a streaming zip reader — the public API (`append()`) does not change.
- **−** Workbook-level features (styles, formulas on other sheets, charts) are
  never touched; the file is re-saved by the same zip library, so
  byte-identity is guaranteed only for entries the appender does not modify
  (it modifies only the target worksheet, and `sharedStrings.xml` +
  registrations in `shared_strings` mode).

## Benchmark

100 000 rows × 3 columns appended to an empty sheet (PHP 8.3, Windows,
`tests/Memory/MemoryBenchmarkTest`, DoD budget = sheet XML × 2.5 + 4 MiB):

| Metric | Value |
|--------|-------|
| Sheet XML after append | 18.0 MB |
| Peak memory (exact allocator accounting) | ~0.4 MB over baseline (budget: 49 MB) |
| Wall time | ~8.3 s |

Peak stays flat because rows stream straight to a temp file: the sheet XML is
never held in memory together with the generated rows. The test fails if the
peak ever exceeds the DoD budget.

## Testing

```bash
composer check          # cs-fixer (dry-run) + phpstan (max, strict) + phpunit
composer test:coverage  # with coverage (xdebug)
```

29 corner cases (C1–C29) and the full matrix are tracked in
`tests/coverage-matrix.md`.

## License

MIT
