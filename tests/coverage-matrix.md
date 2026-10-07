# Coverage matrix: corner cases C1–C29 → tests

Status legend: **done** — automated test exists and passes; **partial** — one side covered,
the other arrives with the stage noted; **planned** — not yet implemented (stage per spec §5).

Stage map (spec §5): Stage 0 setup · Stage 1 read/inspect · Stage 2 writer (`inline_str`,
splicing, validation on emit) · Stage 3 `shared_strings` mode · Stage 4 locks/errors/size
· Stage 5 Laravel integration (ServiceProvider, facade, config, testbench) · Stage 6
docs/CI release.

| Case | Description | Status | Tests |
|------|-------------|--------|-------|
| C1 | Empty sheet (`<sheetData/>`), start A1 | done | read: `Integration/WorkbookInspectorTest::testScanReportsEmptySelfClosedSheetData`, `::testScanReportsEmptyExplicitlyClosedSheetData`; write: `Integration/XlsxAppenderTest::testAppendsToEmptySheetAtCellA1`, `testWriteHeaderInitializesEmptySheetWithData`, `Unit/SheetSplicerTest::testRewritesSelfClosedSheetDataToInsertRowsIntoAnEmptySheet` |
| C2 | `start_cell` intersects existing rows | done | `Integration/XlsxAppenderTest::testStartCellInsideExistingDataThrows`, `testStartCellAtExistingLastRowThrows` (file byte-identical after the throw) |
| C3 | Start row inside a gap (r=1..5, 10 exist, start=6) | done | `Integration/XlsxAppenderTest::testStartInsideARowGapThrows` (v1: forbidden, documented), `testStartsAfterAGapAtTheNextFreeRow` |
| C4 | Sheet names with spaces/Cyrillic/`&`/quotes | done | `Integration/WorkbookInspectorTest::testResolvesSheetNamesWithSpacesCyrillicAndXmlSpecialCharacters`, `::testSelectsFirstSheetWhenNameIsOmitted` |
| C5 | Unknown sheet name | done | `Integration/WorkbookInspectorTest::testUnknownSheetReportsAvailableSheetNames`, `::testUnknownSheetInWorkbookWithoutSheetsReportsNoSheets`, `::testNullSheetNameInWorkbookWithoutSheetsIsInvalid`, `::testEmptySheetNameIsRejected`; `Unit/SheetNotFoundExceptionTest` |
| C6 | `sharedStrings.xml` absent + `shared_strings` mode | done | `Integration/XlsxAppenderTest::testSharedStringsModeCreatesThePartAndItsRegistrations` (sst + content-type Override + workbook relationship, cells `t="s"`), `::testSharedStringsHeaderLabelSharesTheIndexWithTheSameDataValue`, enum form `::testStringModeEnumBehavesLikeItsStringValue`, `::testSharedStringsModeEmptyBatchDoesNotCreateThePart`, `::testSharedStringsWriteFailureRaisesWriteFailedAndLeavesFileUnchanged` (selective `failAddFileEntry`; file byte-identical via `unchangeAll()` rollback); store: `Unit/SharedStringsStoreTest` (fresh build, CT/rel patches added exactly once) |
| C7 | Wrong `count/uniqueCount` in `sharedStrings.xml` | done | `Integration/XlsxAppenderTest::testSharedStringsModeReusesExistingPartAndRefreshesStaleCounts` (stale 999/777 → 1001/3, existing ordinal reused, CT/rels untouched), `::testSharedStringsModeFailureLeavesEveryEntryByteIdentical`; store: `Unit/SharedStringsStoreTest::testStaleCountAndUniqueCountAttributesAreRefreshed`, `::testExistingPartIsParsedAndDuplicatesReuseTheFirstOrdinal`, `::testRichTextRunsAreConcatenatedAndPhoneticRunsAreIgnored`, `::testEmptySiElementMapsToTheEmptyString`, `::testSelfClosedSstPartIsTurnedIntoAnOpenElement`, `::testMissingClosingSstTagIsRejected`, `::testEmptySstPartIsRejected`; part read: `Integration/WorkbookInspectorTest::testReadSharedStringsReturnsNullWhenThePartIsMissing`, `::testReadSharedStringsReturnsTheExistingPart`, `::testReadSharedStringsEnforcesTheSizeLimit`, `::testReadSharedStringsRejectsAnUnreadablePart` |
| C8 | XML special chars, newline/tab, emoji, edge spaces | done | `Unit/RowXmlGeneratorTest::testRoundTripsXmlSpecialCharactersAndEmoji` (incl. `\n`, `\t`, `\r`), `::testMarksEdgeSpacesWithXmlSpacePreserve`; end-to-end `Integration/XlsxAppenderTest::testSpecialCharactersAndEmojiRoundTrip` |
| C9 | Cell string longer than 32767 chars | done | `Unit/RowXmlGeneratorTest::testAcceptsStringAtExactLengthLimit`, `::testRejectsStringBeyondLengthLimit`; end-to-end `Integration/XlsxAppenderTest::testLongStringThrowsAndFileStaysUnchanged` |
| C10 | Empty iterable (`write_header=true`, empty sheet → header written, return 0) | done | `Integration/XlsxAppenderTest::testWriteHeaderOnlyRunReturnsZeroButWritesHeader`, `testEmptyIterableReturnsZeroAndKeepsFileUntouched` |
| C11 | Worksheet XML > `max_sheet_xml_size` | done | `Integration/WorkbookInspectorTest::testReadSheetXmlRespectsTheConfiguredSizeLimit`, `Integration/XlsxAppenderTest::testWorksheetSizeLimitThrows` |
| C12 | File locked by another process (`use_lock=true`) | done | `Integration/XlsxAppenderTest::testLockTimeoutRaisesLockTimeoutExceptionAndLeavesFileUnchanged` (foreign lock on the sidecar, `lock_timeout=0.05`), `::testLockIsReleasedAfterSuccessfulAppend`, `::testLockIsReleasedWhenAppendThrows`, `::testUseLockFalseAppendsWhileAForeignLockIsHeld`; option validation in `invalidOptionProvider` (`use_lock`, `lock_timeout`). Lock lives in a sidecar `<file>.lock` — flock on the target itself blocks `ZipArchive::open()` for writing on win32 (empirical, see spec §2.1) |
| C13 | No write permission / read-only file or directory | done | `Integration/XlsxAppenderTest::testReadOnlyFileThrowsWriteFailed`, `testReadOnlyDirectoryThrowsWriteFailed` (platform-gated skip), `Unit/SheetSplicerTest::testRejectsWhenTheTemporaryDirectoryIsNotWritable` |
| C14 | `<sheetData>` missing or XML malformed | done | read: `WorkbookInspectorTest::testScanRejectsWorksheetWithoutSheetData`, `::testScanRejectsUnclosedSheetData`, `::testScanRejectsMalformedXml`, `::testScanRejectsEmptyWorksheetXml`, `::testThrowsWhenWorkbookXmlIsMalformed` and friends; write: `Unit/SheetSplicerTest::testRejectsSheetWithoutClosingSheetData` |
| C15 | Numbers: `-0.0`, `INF`, `NAN`, `PHP_INT_MAX`, `1e-300` | done | `Unit/RowXmlGeneratorTest::testSerializesFloatsWithoutScientificNotation` (provider: `-0.0`, `1e-300`, `1e300`, negative exponents), `::testRejectsNonFiniteFloats`, `::testEmitsIntegerWithoutTypeAttribute`; end-to-end `Integration/XlsxAppenderTest::testNumericEdgeValuesAreWrittenPlainly`, `testInfValueThrowsAndFileStaysUnchanged` |
| C16 | Mixed types + `null` in the middle (`skip_nulls`) | done | `Unit/RowXmlGeneratorTest::testSkipsNullCellsByDefault`, `::testWritesEmptyCellWhenNullsAreKept`; end-to-end `Integration/XlsxAppenderTest::testNullInTheMiddleIsSkippedByDefault`, `testNullInTheMiddleIsWrittenWhenSkipNullsIsDisabled`, `testDateTimeValueThrowsAndFileStaysUnchanged` |
| C17 | Files from LibreOffice / Google Sheets / WPS / PhpSpreadsheet | done | read/scan: `WorkbookInspectorTest::testScansLibreOfficeStyleWorksheet`, `::testScansGoogleSheetsStyleWorksheet`, `::testScansPhpSpreadsheetGeneratedFile`; rewrite pass-through: `Integration/XlsxAppenderTest::testPassThroughPreservesGeneratorSpecificMarkup`; WPS fixture → pre-release manual check |
| C18 | Column > Z up to XFD, > 16384 rejected | done | `Unit/ColumnNameTest`, `Unit/CellAddressTest` (limits, invalid refs) |
| C19 | Second `append()` to the same file | done | `Integration/XlsxAppenderTest::testSecondAppendUsesAFreshScan` (fresh scan per `append()` — spec correction) |
| C20 | File > 4 GB (zip64) | done | `Integration/XlsxAppenderTest::testArchivesLargerThanFourGigabytesAreRejected` (real 4 GB+ file via `ftruncate`, skipped when the filesystem refuses) → `UnsupportedArchiveException` with the documented v1 limitation |
| C21 | `write_header=true`, empty sheet, `start_cell='A2'` | done | `Integration/XlsxAppenderTest::testWriteHeaderInitializesEmptySheetWithData` (dimension merged to `A1:B3`) |
| C22 | `write_header=true`, `start_cell='A1'` conflict | done | `Integration/XlsxAppenderTest::testWriteHeaderAtRowOneThrows` (`InvalidCellReferenceException`, file untouched) |
| C23 | `write_header=true`, sheet already has rows | done | `Integration/XlsxAppenderTest::testWriteHeaderOnNonEmptySheetThrows` (`HeaderConflictException`, byte-identical file) |
| C24 | `columns=[key=>label]` mapping, missing/extra keys | done | `Unit/ColumnMapperTest` (list/assoc forms, recordMap, strict, objects), `Integration/XlsxAppenderTest::testStrictColumnsRejectsExtraKeys`, `testWriteHeaderWithoutColumnsThrows`, `testMapCallbackTransformsRecords` |
| C25 | Positional arity ≠ W with `columns` | done | `Unit/ColumnMapperTest::testPositionalRecordMustMatchColumnCount`, `Integration/XlsxAppenderTest::testPositionalArityMismatchThrows` |
| C26 | `start_col + W − 1 > 16384` | done | `Integration/XlsxAppenderTest::testColumnWidthOverflowThrows`, `Unit/RowXmlGeneratorTest::testRejectsColumnBeyondXfd`, `::testAcceptsCellValueAtLastColumn` |
| C27 | Cyrillic labels in header, UTF-8, reopen in Excel/LO/GS | done (automated) | header emission through `Unit/SheetSplicerTest::testWritesHeaderRowBeforeData` + header integration tests (UTF-8 bytes asserted); Excel/LO/GS reopen → pre-release manual |
| C28 | `conflict_mode='clear'` — rows from `start_cell` to the end of the sheet are dropped before the write | done | `Integration/XlsxAppenderTest::testConflictModeClearRemovesRowsFromStartRowToTheEnd` (rows 1..5 → 1,2 + new 3,4, dimension `A1:A4`), `::testConflictModeClearBelowExistingDataActsLikeAppend` (nothing to drop), `::testConflictModeClearWithNoRecordsLeavesFileByteIdentical` (empty batch no-op), enum form `::testConflictModeEnumBehavesLikeItsStringValue`; rewrite plumbing `Unit/SheetSplicerTest::testClearModeDropsExistingRowsFromStartRowToTheEnd`, `testConflictModeRejectsPrefixWithoutOpeningSheetDataTag`; partitioning `Unit/SheetRowPartitionerTest`; option validation in `invalidOptionProvider` (`conflict_mode`) |
| C29 | `conflict_mode='overwrite'` — only the rows covered by the batch are replaced, rows above/below survive | done | `Integration/XlsxAppenderTest::testConflictModeOverwriteReplacesCoveredRowsAndKeepsTheRest`, `::testConflictModeOverwriteExtendsPastExistingData`, `::testConflictModeOverwriteInsideARowGapKeepsLaterRows` (gap start now legal, ascending `r` order asserted); `Unit/SheetSplicerTest::testOverwriteModeReplacesCoveredRowsAndKeepsTheRest`; partitioning `Unit/SheetRowPartitionerTest` |

## Structural test inventory

- `Unit/ColumnNameTest` — `ColumnName::of()` / `indexOf()`, limits (C18, C26 primitives); also covers `ExcelLimits`.
- `Unit/CellAddressTest` — parsing, dollar anchors, invalid refs (C22, C26 primitives).
- `Unit/SheetScanResultTest` — value object (C1 read contract).
- `Unit/SheetNotFoundExceptionTest` — message and `availableSheets` (C5).
- `Unit/OptionEnumsTest` — `ConflictMode`/`StringMode` backed values match the
  `conflict_mode`/`mode` string option values (enum option form).
- `Unit/RowXmlGeneratorTest` — cell emission, value validation, float/string encoding (C8, C9, C15, C16, C26).
- `Unit/ColumnMapperTest` — `columns` forms, recordMap, strict mode, object records (C24, C25).
- `Unit/SheetSplicerTest` — row insertion, header rows, dimension merge, temp-file lifecycle, verify(), clear/overwrite rewrite plumbing (C1, C13, C14 write side, C28, C29).
- `Unit/SheetRowPartitionerTest` — row bucketing for the rewrite: clear/overwrite ranges, missing `r`, self-closed rows, whitespace, missing `</row>` (C28, C29).
- `Unit/SharedStringsStoreTest` — sst parse/dedup/index allocation, count refresh, self-closed part, CT/rel registrations, malformed parts (C6, C7); declares `SharedStringsStore`.
- `Integration/WorkbookInspectorTest` — workbook resolution, quirks, invalid files (C4, C5, C11, C14, C17), `readSharedStrings()` size limit/read failures; declares `SheetDataScanner`.
- `Integration/XlsxAppenderTest` — end-to-end append contract: conflicts, header modes, validation order, atomicity (C1–C3, C8–C11, C13, C15–C16, C19, C21–C29); `shared_strings` mode wiring (C6, C7); sidecar locking lifecycle and `use_lock=false` (C12); >4 GB fast-fail (C20).
- `Laravel/ServiceProviderTest` — config defaults per spec §3.3, non-singleton container bindings, `path`/`options` parameter validation, config narrowing (non-array and integer-keyed configs), `vendor:publish --tag=xlsx-appender` path registration; declares `XlsxFastAppenderServiceProvider`.
- `Laravel/FacadeTest` — facade `append()`/`make()`, per-call overrides (`sheet`, `start_cell`), Eloquent collection mapped by `getAttribute`, `LazyCollection` streaming, chunked batch appends (stage-5 exit criteria); exercises `AppenderFactory`.
- `Support/InteractsWithTempFiles` — temp-file/ZipArchive lifecycle trait shared by the PHPUnit `TestCase` and the Testbench `LaravelTestCase`.
- `Support/XlsxFixtureFactory` — minimal valid workbooks, generator-style worksheets, self-closed empty sheet with `<dimension>` (C17 fixtures), optional extra entries (pre-existing sst part for C7).
- `Support/FailingZip` — archive double: read failures between `statName()` and `getFromName()`, forced `addFile()`/`close()` failures, selective `failAddFileEntry` (defensive branches).

## Known intentionally-uncovered statements

Sixteen defensive statements stay uncovered — each is a system-failure branch whose
preconditions are checked earlier or unobservable from userland:

- `RowXmlGenerator::floatToPlainDecimal()` — `json_encode() === false` for a float (never happens
  on PHP 8.3) and the plain-decimal split branch for mid-range *positive* exponents (PHP's
  `json_encode()` only emits `e+N` for magnitudes where that branch is arithmetically unreachable;
  verified by probe).
- `SheetDataScanner` — the empty `private` constructor of a static utility class (never instantiated).
- `SheetRowPartitioner` — the same empty `private` constructor of a static utility class.
- `SheetSplicer` — `fopen()`/`fwrite()`/`fread()` failure throws; the unwritable-directory case is
  covered first (`testRejectsWhenTheTemporaryDirectoryIsNotWritable`), the rest are OS-level
  failures with no stable way to provoke them in CI.
- `SharedStringsStore::load()` — `XMLReader::XML()` returning `false` for a non-empty string
  (verified by probe: every non-empty input yields a reader; errors surface on `read()`; `''` is
  rejected earlier by `fromXml()` with a dedicated message).
- `XlsxAppender::writeTempFile()` — `file_put_contents()` returning a short count for a path that
  was just written successfully in the same method (OS-level failure).
- `XlsxAppender::acquireLock()` — `fopen()` on the sidecar lock file failing while the target
  itself passed `is_writable()` (only with exotic ACLs; no stable way to provoke in CI).

## PHPUnit coverage filtering note

`#[CoversClass(...)]` makes PHPUnit attribute **only** the declared class per test; hits in other
`src/` files executed by that test are discarded. Every `src/` class therefore needs a test that
declares it (its own unit test or an extra `#[CoversClass]` attribute), otherwise the class shows
0% even though integration tests exercise it. `WorkbookInspectorTest` additionally declares
`SheetDataScanner`; `ColumnNameTest` declares `ExcelLimits`.