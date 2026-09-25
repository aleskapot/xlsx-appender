# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-25

### Added

- `XlsxAppender::append()` — appends rows to an existing XLSX worksheet without
  re-saving the workbook (variant C: `ZipArchive` + streaming `XMLWriter`);
  returns the number of written rows.
- Sheet resolution by name (`sheet`) or first sheet, full workbook/relationship
  parsing with descriptive errors (`SheetNotFoundException` with available
  names, `InvalidWorkbookException`).
- Worksheet splicing with pass-through of all generator-specific markup outside
  `<sheetData>` (C17), `<dimension>` merge, gap detection (C3) and row-conflict
  detection (C2).
- `inline_str` and `shared_strings` modes; shared-strings parts are parsed,
  deduplicated, count-corrected, and created with content-type/relationship
  registrations when missing (C6, C7).
- Header and key-based mapping: `columns`, `write_header`, `strict_columns`,
  `map()` callback; Eloquent/`getAttribute()` object records (C21–C25).
- Value serialisation with Excel limits: 32 767-char strings (C9), XML
  forbidden characters, non-finite floats, `-0.0`/E-notation normalisation
  (C15), `skip_nulls` (C16).
- Sidecar `flock` locking (`use_lock`, `lock_timeout`) serialising concurrent
  writers, with `LockTimeoutException` on timeout (C12).
- Size guards: `max_sheet_xml_size` for worksheet and shared-strings reads
  (C11), fast-fail for archives above 4 GB with `UnsupportedArchiveException`
  (C20).
- Atomicity: verified temp splice, uncommitted-entry rollback
  (`unchangeAll()`) and lock release in `finally` — failures leave the file
  byte-identical.
- Typed exception hierarchy under `AppenderException`.
- Laravel integration: auto-discovered `XlsxFastAppenderServiceProvider`,
  `XlsxAppender` facade, publishable `config/xlsx-appender.php`
  (`vendor:publish --tag=xlsx-appender`), non-singleton container bindings.
- Quality gates: PHPUnit 11 (C1–C27 matrix in `tests/coverage-matrix.md`),
  PHPStan level max + strict rules, PHP CS Fixer, coverage gate (≥ 90% line /
  ≥ 75% branch, currently ~98.5% / ~97%), memory benchmark for the DoD budget
  (100k rows within XML × 2.5 + 4 MiB), GitHub Actions CI for PHP 8.2–8.4.
