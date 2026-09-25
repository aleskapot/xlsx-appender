# Спецификация: PHP Composer-пакет для быстрой вставки данных в существующий XLSX-лист

> **Статус:** черновик для передачи агенту-разработчику
> **Дата:** 2026-09-24
> **База:** исследование стриминговых подходов к xlsx (PhpSpreadsheet, OpenSpout, php-xlsx-fast-editor) — выбран **вариант C**: `ZipArchive` + потоковая запись через `XMLWriter`, пик памяти ≈ размер XML целевого листа.

---

## 1. Цель и нецели

### Цель
Composer-пакет (рабочее имя: `vendor/xlsx-fast-appender`, неймспейс `XlsxFastAppender\`), добавляющий **большой объём строк** в **существующий** xlsx-лист, начиная с заданной ячейки, с минимальным потреблением памяти. Данные принимаются из любого `iterable` (включая Eloquent Collection и генераторы).

### Неоцели (v1)
- Создание нового файла/листа с нуля (это задача OpenSpout).
- Чтение данных из xlsx.
- Изменение стилей, ширины колонок, формул, диаграмм, условного форматирования.
- Вставка в середину листа / перенос существующих строк вниз (только append от указанной позиции).
- Поддержка .xls (бинарный формат) и .ods.

---

## 2. Архитектура (вариант C)

### 2.1. Пайплайн одного вызова `append()`

```
1. open:      ZipArchive::open($path, CREATE)
2. resolve:   workbook.xml + xl/_rels/workbook.xml.rels → путь sheetN.xml по имени листа
3. read:      $sheetXml = zip->getFromName($sheetPath)        ← строка целиком (вариант C!)
              проверка размера ≤ max_sheet_xml_size, иначе AppenderSizeLimitException
4. scan:      XMLReader по строке → последняя существующая строка (r=), наличие <sheetData>;
               при write_header — также фиксация «пуст/непуст» листа
5. validate:  startRow > lastExistingRow (иначе StartCellConflictException);
               при write_header: лист пуст и start_row ≥ 2; ширина: start_col + W − 1 ≤ 16384 (см. 3.5)
6. splice:    XMLWriter → tmp-файл: pass-through исходного XML,
              перед </sheetData> — новые <row> из iterable
7. (opt)      sharedStrings: чтение/дополнение xl/sharedStrings.xml (см. 3.2)
8. save:      zip->addFromString(sheetPath, $newXml); при создании sst — addFromString +
              правка [Content_Types].xml и workbook.xml.rels; close()
9. verify:    XMLReader по новой записи: ровно один </sheetData>, счётчик строк = old + N
10. done:     (flock удерживался на шаге 1) — при ошибке tmp удаляется, исходный файл нетронут
```
> **Locking (C12):** замок ставится на sidecar-файл `<file>.lock`, а не на целевой xlsx: эмпирический тест (PHP 8.3/win32) показал, что удержание `flock(LOCK_EX)` на целевом файле мешает `ZipArchive::open()` открыть его для записи (ошибка code 5). Sidecar одинаково работает на всех платформах. Lock-файл намеренно не удаляется (гонка удаления могла бы разорвать взаимоблокировку).

> **Важно:** запись sheetN.xml в zip заменяется целиком (`addFromString`), все остальные записи архива не трогаются. Tmp-файл создаётся рядом с целевым (тот же filesystem) — для `rename()` при необходимости и для разбора полётов по логу.

### 2.2. Почему не O(1)
`ZipArchive::getFromName()` возвращает строку целиком → пик памяти ≈ размер XML листа + ~2× на время splicing (входная строка + буферы XMLWriter). Это осознанное ограничение варианта C: при превышении `max_sheet_xml_size` (default **256 МБ**, настраивается) бросать исключение с явным сообщением, а не молча убивать воркер.

### 2.3. Формат новых ячеек
- Строки: `t="inlineStr"` → `<c r="A51" t="inlineStr"><is><t>текст</t></is></c>` (режим `inline_str`, **default**) — sharedStrings.xml не трогаем, словарь не растёт.
- Числа: `<c r="C51"><v>199.99</v></c>`; int и float различаются по типу PHP-значения; float сериализуется с максимальной точностью (без потери, без локали — `LC_NUMERIC` игнорируется, всегда точка).
- Булевы: `<c r="D51" t="b"><v>1</v></c>` (опционально, см. 3.4).
- NULL / null-значения: ячейка пропускается (не создаётся) — настраивается `skip_nulls` (default true).

---

## 3. Публичный API

### 3.1. Ядро (фреймворк-агностичное)

```php
$appender = new XlsxFastAppender\XlsxAppender(
    path: '/data/report.xlsx',
    options: [
        'sheet'               => 'Data',      // имя листа (default: первый лист)
        'start_cell'          => 'A2',        // с какой ячейки начинать (default: A1)
        'mode'                => 'inline_str',// 'inline_str' | 'shared_strings'
        'max_sheet_xml_size'  => 268435456,   // байты, default 256MB
        'use_lock'            => true,        // flock на sidecar-файле <file>.lock
        'lock_timeout'        => 30.0,        // сек, non-blocking polling
        'skip_nulls'          => true,
    ],
);

$written = $appender->append($iterable);   // int — число записанных строк
```

- `$iterable` — любой `Traversable|array`: `Generator`, `array`, `Illuminate\Support\Collection` (включая lazy), `ChunkedCursor`. Каждая запись — `array` (порядок = порядок колонок, начиная со стартовой колонки) или объект с маппингом через callback.
- Маппинг записей: `$appender->map(fn($item) => [$item->id, $item->name, $item->amount])` — для Eloquent-моделей и произвольных объектов. Без `map()` array'ы проходят как есть.
- Имена колонок, key-based маппинг записей и запись строки заголовка («формирование листа») — опциональные `columns` / `write_header`, см. §3.5.
- Возврат `int` (число строк) позволяет логировать прогресс в queue job.

### 3.2. Режим `shared_strings` (дедупликация, опционально)
- При open читаем `xl/sharedStrings.xml` (если существует) строкой целиком, парсим в hash-таблицу `строка → индекс` (с учётом существующих `<si>`).
- Новые строки: если уже есть в таблице — переиспользуем индекс; иначе добавляем `<si><t>` и новый индекс. Таблица растёт только на **уникальные** строки батча.
- Обновляем атрибуты `count` (общее число ссылок, включая существующие) и `uniqueCount`.
- Если файла нет — создаём (`sst` с namespace), добавляем Override в `[Content_Types].xml` и relationship в `xl/_rels/workbook.xml.rels` (type `sharedStrings`).
- **Документировать trade-off:** режим экономит размер файла при большом числе повторяющихся значений, но память = O(уникальных строк). Default — `inline_str`.

### 3.3. Laravel-интеграция
- `composer.json`: `extra.laravel.providers` → автооткрытие ServiceProvider (Laravel ≥ 5.5 discovery).
- `XlsxFastAppender\XlsxFastAppenderServiceProvider`:
  - bind `XlsxAppender` в контейнер (factory, не singleton; конфиг из `config('xlsx-appender.*')`);
  - Facade `XlsxAppender::append($path, $iterable, array $overrides = [])`;
  - публикация конфига: `php artisan vendor:publish --tag=xlsx-appender`.
- Конфиг по умолчанию: `sheet=null`, `start_cell='A2'`, `mode='inline_str'`, `max_sheet_xml_size=268435456`, `use_lock=true`.
- Документированный паттерн для queue job (в README, не в пакете):

```php
class AppendReportRowsJob implements ShouldQueue {
    public function handle(XlsxAppender $appender) {
        // lazy() — генератор, память O(1) по стороне БД:
        $written = $appender->append(
            ReportRow::query()->lazy(5_000)->map(fn($r) => [$r->id, $r->name, $r->amount])
        );
    }
}
```

- Пакет **не** зависит от Laravel в ядре (`illuminate/*` только `require-dev` для интеграционных тестов).

### 3.4. Типы значений v1
| PHP | XLSX | Примечание |
|---|---|---|
| `string` | inlineStr / shared string | экранирование XML обязательно |
| `int`, `float` | `<v>` | float: точное представление (максимальная точность, без scientific notation) |
| `bool` | `t="b"` | 0/1 |
| `null` | пропускается | если `skip_nulls=true` |
| `\DateTimeInterface` | — | **не поддерживается в v1** → типизированное исключение с подсказкой «преобразуйте в строку/число сами» |

### 3.5. Имена колонок и формирование листа (опционально)

Две опциональные опции расширяют позиционный API; без них поведение идентично §3.1.

**`columns`** — имена/ключи колонок и фиксированная ширина строки `W = count(columns)`. Порядок элементов = порядок столбцов слева направо, начиная со стартовой колонки `start_cell`. Две формы:

| Форма | Ключ для чтения записи | Текст в строке заголовка |
|---|---|---|
| список строк `['id','name']` | сам элемент | сам элемент |
| ассоц. массив `[ключ=>лабел]` | ключ (`user_id`) | значение (`User ID`) |

Форма `[ключ=>лабел]` — когда машиное имя (атрибут модели / DB-колонка) отличается от текста шапки, в т.ч. при русском языке: `'name' => 'Имя'`.

**Маппинг записи → ячейки** (для i-й колонки `key_i`):

```php
function cellValue(mixed $record, string $key): mixed {
    if (is_array($record))  return $record[$key] ?? null;          // отсутствующий ключ → null
    if (is_object($record)) {
        if (method_exists($record, 'getAttribute')) return $record->getAttribute($key); // Eloquent
        if (property_exists($record, $key))         return $record->{$key};
        if (method_exists($record, '__get'))        return $record->{$key};
    }
    return null;
}
```

Приоритет: `map()` > key-based (`columns` + ассоц./объект) > позиционный с проверкой аритарности > дефолтное позиционное.
- **ассоц. массив** — чтение по `key_i`; отсутствующий ключ → `null` (уважает `skip_nulls`); лишний ключ игнорируется, либо исключение при `strict_columns=true`.
- **объект** (Eloquent/DTO) — резолвер выше; снимает необходимость в `map()` для «DB → Excel».
- **позиционный список** при заданных `columns` — i-й элемент = колонка i; аритарность обязана равняться `W`, иначе `ColumnCountMismatchException`.
- буквенные координаты (A..XFD) считаются **по позиции**, от строки имени не зависят.

**`write_header`** — записывает строку заголовков (лабелы из `columns`) в строку `start_row − 1`. Это «формирование листа заново»: инициализация структуры колонок **на пустом листе**. Не является созданием нового файла/листа (non-goal §1) и не стирает существующие строки.

| Состояние листа | Поведение `write_header=true` |
|---|---|
| лист пустой | записать `<row r="start_row−1">`, затем данные с `start_cell` |
| `start_row < 2` | validation-исключение до записи (шапке некуда деться) |
| лист непустой | `HeaderConflictException`, файл не тронут |

Батчинг: батч 1 — `write_header=true` (формирование), батчи 2..N — `write_header=false` (append, C19). Повторный вызов с `write_header=true` на непустой лист = конфликт, а не дублирование шапки. Реализация — минимальная дельта к `SheetSplicer`: на пустом листе перед блоком данных эмитится одна `<row>` с `t="inlineStr"`-ячейками; pass-through и вставка «перед `</sheetData>`» не меняются (§2.1).

**Совпадение ключей.** Ключи сравниваются побайтово и регистрозависимо (`'Имя' ≠ 'имя'`, пробел по краям ломает совпадение). Для данных из БД/Eloquent атрибуты почти всегда латинские → русский текст объявляйте только в позиции лабела: `'name' => 'Имя'`. Отсутствующий ключ даёт `null` **молча** (даже при кириллице); `strict_columns=true` ловит лишь *лишние* ключи. Опционально — fail-fast: если первая запись резолвится целиком в `null`, бросать диагностическое исключение (защита от «пустых колонок»).

**Пример.**
```php
$appender = new XlsxAppender(path: '/data/report.xlsx', options: [
    'sheet'        => 'Data',
    'start_cell'   => 'A2',                                  // 1-я строка — под шапку
    'columns'      => ['name' => 'Имя', 'amount' => 'Сумма'],
    'write_header' => true,
]);
$w1 = $appender->append(ReportRow::query()->lazy(5_000));  // формирование: шапка + данные

// батчи 2..N — append без шапки
$appender2 = new XlsxAppender(path: '/data/report.xlsx', options: [
    'sheet' => 'Data', 'start_cell' => 'A2',
    'columns' => ['name' => 'Имя', 'amount' => 'Сумма'], 'write_header' => false,
]);
$w2 = $appender2->append(ReportRow::query()->where('id','>',$lastId)->lazy(5_000));
```

---

## 4. Требования к качеству

### 4.1. Инструменты (CI обязателен: GitHub Actions, PHP 8.2 / 8.3 / 8.4)
- **PHPUnit 11+**, coverage через pcov/xdebug: **line coverage ≥ 90%, branch ≥ 75%** на `src/`.
- **PHPStan level max** + strict rules, 0 ошибок.
- PHP-CS-Fixer (PSR-12), 0 отклонений.
- Тесты запускаются в CI на каждом PR; coverage gate не даёт слить регресс.

### 4.2. Обязательные категории тестов
1. **Unit** — генерация XML строк/ячеек, маппинг имён колонок (A..Z, AA, AB…), key-based маппинг `columns` (ассоц./объект/позиционный, форма `[ключ=>лабел]`, `strict_columns`, аритарность), позиция шапки = start_row−1, экранирование, resolve пути листа.
2. **Integration** — реальные xlsx-файлы (фикстуры, сгенерированные PhpSpreadsheet в dev-зависимости): append → reopen → проверка значений через независимый reader (PhpSpreadsheet или OpenSpout); «формирование заново» → reopen: 1-я строка = заголовки, батч 2 с `write_header=false` продолжает (C19+C21).
3. **Laravel integration** (orchestra/testbench) — facade, конфиг, автооткрытие, маппинг Eloquent Collection (включая `lazy()` и `chunk()`).
4. **Память** — benchmark-тест: append 100k строк в лист с 10k существующих → пик памяти ≤ размер XML листа × 2.5 + константа; при превышении лимита — исключение, файл не повреждён.
5. **Атомарность** — симуляция сбоя на середине splicing (инъекция ошибки) → исходный файл байт-в-байт не изменён, tmp удалён.

### 4.3. Corner cases (каждый = минимум один тест)
| # | Случай | Ожидаемое поведение |
|---|---|---|
| C1 | Лист пустой (`<sheetData/>` без строк), start=A1 | Строки пишутся с r=1 |
| C2 | `start_cell` внутри существующих данных (пересечение r) | `StartCellConflictException`, файл не тронут |
| C3 | Start-строка выше последней, но в «дыре» (существуют r=1..5 и r=10, start=r=6) | **v1: запрет** — конфликт, т.к. дублирование/перенос строк не поддерживается; документировать |
| C4 | Имя листа с пробелами/кириллицей/`&` (в workbook.xml экранировано) | Корректный resolve по `name`-атрибуту через DOM/XPath, не строковым поиском |
| C5 | Лист не найден | `SheetNotFoundException` со списком доступных имён |
| C6 | `sharedStrings.xml` отсутствует + режим `shared_strings` | Файл создаётся, `[Content_Types].xml` и rels дополняются; Excel/LibreOffice открывают без ошибок |
| C7 | `sharedStrings.xml` есть, но `count/uniqueCount` не совпадают с реальностью (битые файлы) | Считать фактические значения, перезаписать атрибуты |
| C8 | XML-спецсимволы в строках: `& < > " '`, newline, tab, emoji (4-byte UTF-8), leading/trailing пробелы | Корректное экранирование; для строк с пробелами по краям — `xml:space="preserve"` на `<t>`/`<si>` |
| C9 | Строка длиннее 32767 символов (лимит Excel) | Исключение **до** начала записи, с указанием номера строки и колонки |
| C10 | Пустой iterable | Файл не изменяется (zip не переписывается), return 0 |
| C11 | XML листа > `max_sheet_xml_size` | `AppenderSizeLimitException`, файл не тронут |
| C12 | Файл открыт/заблокирован другим процессом (`use_lock=true`) | Ожидание до `lock_timeout`, затем `LockTimeoutException` (замок на sidecar `<file>.lock`, см. 2.1); при `use_lock=false` — race, задокументировано |
| C13 | Нет прав на запись в каталог (tmp) / файл read-only | Типизированное исключение, понятный message |
| C14 | `<sheetData>` отсутствует или не закрывается (невалидный xlsx) | `InvalidWorkbookException` до начала записи |
| C15 | Числа: `-0.0`, `INF`, `NAN`, очень большие int (PHP_INT_MAX), float 1e-300 | INF/NAN → исключение; остальные — точная сериализация, проверено reopen'ом |
| C16 | Смешанные типы в одной строке + null в середине (skip_nulls=true) | Пропущенные ячейки не сдвигают колонки: следующая записанная ячейка получает **правильную** буквенную координату (пропуск = отсутствие `<c>`, а не сдвиг) |
| C17 | Запись в файл, созданный LibreOffice / Google Sheets / WPS (разные варианты XML: отсутствующие атрибуты, `dimension` без ref, extra-элементы после sheetData) | Pass-through сохраняет всё неизменным; reopen читается корректно. Фикстуры минимум от трёх генераторов |
| C18 | Колонка за Z (AA, AZ, … > 16384 — лимит Excel XFD) | Корректный перевод индекса в имя; > 16384 → исключение |
| C19 | Повторный append в тот же файл (2-й батч после 1-го) | lastRow пересчитывается, строки продолжаются; N батчей = линейный I/O |
| C20 | Файл > 4 ГБ (zip64) | Задокументировать ограничение v1: ZipArchive PHP с zip64 работает не во всех сборках — проверять и бросать понятную ошибку |
| C21 | `write_header=true`, лист пустой, `start_cell='A2'` | Шапка на r=1 (лабелы из `columns`), данные с r=2; reopen: 1-я строка = заголовки |
| C22 | `write_header=true`, `start_cell='A1'` (нет строки сверху) | Validation-исключение до записи, файл не тронут |
| C23 | `write_header=true`, лист уже содержит строки | `HeaderConflictException`, файл байт-в-байт не изменён |
| C24 | `columns=[ключ=>лабел]`: чтение по ключу, шапка по лабелу; отсутствующий ключ → null (skip); лишний ключ → игнор / исключение при `strict_columns` | Корректный маппинг; reopen совпадает |
| C25 | Позиционная запись с аритарностью ≠ W при заданных `columns` | `ColumnCountMismatchException` до записи |
| C26 | `start_col + W − 1 > 16384` (лимит XFD) при заданных `columns` | Исключение до записи (расширение C18) |
| C27 | Кириллические имена: `[латинский_ключ => русский_лабел]`; шапка UTF-8; reopen читается Excel/LibreOffice/Google Sheets без ошибок; координаты по позиции не зависят от имени | Шапка на русском корректна; рассогласование русских ключей с атрибутами → пустые ячейки (задокументировано, опц. fail-fast) |

### 4.4. Производительность (acceptance, benchmark в CI nightly, не gate)
- ≥ **20k строк/сек** на CPU среднего класса для `inline_str` режима (ориентир: OpenSpout ~30–50k строк/сек на создание).
- Время append 1 млн строк ≤ 60 сек.

---

## 5. Этапы реализации

> Каждый этап заканчивается зелёным CI (tests + phpstan + cs-fixer) и merge-ready PR'ом. TDD: тесты пишутся до/вместе с кодом этапа.

### Этап 0 — Каркас (0.5 дня)
- Init composer package: `composer.json` (php >=8.2, ext-zip, ext-dom, ext-xml; require-dev: phpunit, phpstan, cs-fixer, orchestra/testbench, phpspreadsheet), PSR-4, GitHub Actions CI, LICENSE (MIT — решение владельца).
- **Выход:** пустой пакет проходит CI.

### Этап 1 — Read-слой (1 день)
- `WorkbookInspector`: resolve пути листа по имени (workbook.xml → rels → sheetN.xml), список имён листов, определение последней строки через XMLReader.
- Тесты: C4, C5, C14 + фикстуры от трёх генераторов (C17 — только read-часть).
- **Выход:** можно программно сказать «где лежит лист и где его последняя строка».

### Этап 2 — Генерация строк и splicing, режим `inline_str` (2 дня)
- `RowXmlGenerator`: значение → XML ячейки (C8, C9, C15, C16, C18), имя колонки.
- `ColumnMapper` + header: опциональные `columns`/`write_header` — key-based маппинг записи и строка заголовков на пустом листе (§3.5; C21–C27).
- `SheetSplicer`: XMLReader pass-through + вставка перед `</sheetData>` → tmp; лимит размера (C11); атомарность (тест 4.2.5).
- Тесты: C1, C2, C3, C10, C13, C19, C21–C27 + integration reopen.
- **Выход:** базовый сценарий «вставить N строк в существующий лист» работает и покрыт.

### Этап 3 — Режим `shared_strings` (1–2 дня)
- `SharedStringsStore`: чтение/создание/дедупликация, count/uniqueCount (C6, C7), правка `[Content_Types].xml` + rels при создании.
- Тесты: C6, C7 + reopen-проверки + benchmark памяти против inline_str на датасете с 90% дублей.
- **Выход:** оба режима работают; README объясняет trade-off и выбор default.

### Этап 4 — Robustness (1 день)
- Locking (C12), типизированные исключения, верификация после записи (шаг 9 пайплайна), обработка zip64 (C20), лимиты колонок/строк Excel.
- Тесты: C12, C20 + атомарность под симуляцией сбоя.
- **Выход:** пакет безопасен в многопроцессной среде queue.

### Этап 5 — Laravel-интеграция (1 день)
- ServiceProvider, facade, конфиг, автооткрытие; тесты на testbench (маппинг Eloquent Collection, lazy, chunk).
- **Выход:** `XlsxAppender::append(...)` из конфига работает в приложении.

### Этап 6 — Документация и релиз (0.5–1 день)
- README: быстрый старт, оба режима, паттерн queue job с `lazy()`, таблица исключений, лимиты (размер листа, колонки, длина строки), benchmark-результаты.
- CHANGELOG, tag v1.0.0, публикация на Packagist.
- **Выход:** v1.0.0.

**Итого: ~7–9 рабочих дней.**

---

## 6. Definition of Done (v1.0)
- [ ] `composer require vendor/xlsx-fast-appender` → append в существующий лист работает из CLI и Laravel.
- [ ] Оба режима (`inline_str`, `shared_strings`) покрыты интеграционными тестами с reopen-проверкой.
- [ ] Все 27 corner cases (раздел 4.3) имеют тесты; line coverage ≥ 90%.
- [ ] Опциональные `columns` + `write_header`: формирование листа с шапкой и key-based маппинг покрыты тестами (C21–C27).
- [ ] Память: пик ≤ размера XML листа × 2.5 + константа на 100k строк (benchmark-тест).
- [ ] Атомарность: сбой на любом этапе не повреждает исходный файл.
- [ ] PHPStan max, CS-Fixer — чисто; CI зелёный на PHP 8.2–8.4.
- [ ] README с trade-off'ами и паттерном queue job.

---

## 7. Риск-реестр
| Риск | Митигация |
|---|---|
| Лист больше лимита памяти варианта C | Явное исключение + документированный путь миграции на стриминговый вариант A/B (unzip-пайп / свой zip-ридер) — API пакета это позволяет: заменить только `read`/`splice` слои |
| Разнобой XML от разных генераторов файлов | Фикстуры от Excel/LibreOffice/Google Sheets в репо; pass-through гарантирует сохранение неизменённых узлов |
| Race при параллельных append | flock по умолчанию + рекомендация одного воркера на файл в README |
| Невалидный xlsx как вход | Ранняя валидация (C14) до любой записи |
| Рассогласование ключей `columns` с атрибутами записи (в т.ч. кириллические) → молча пустые колонки | Форма `[ключ=>лабел]` + документированное предупреждение (§3.5); опц. fail-fast на первую все-null запись |
