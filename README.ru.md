# rasuvaeff/schema

![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/schema?label=stable)
![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/schema?label=downloads)
![Build](https://github.com/rasuvaeff/schema/actions/workflows/build.yml/badge.svg)
![Static analysis](https://github.com/rasuvaeff/schema/actions/workflows/static-analysis.yml/badge.svg)
![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)
![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/schema/php)
![License](https://img.shields.io/packagist/l/rasuvaeff/schema?label=license)

Schema-as-code для PHP: одно определение, два порта. То же дерево
`Schema::object([...])` валидирует рантайм-вход **и** кормит property-тесты
генератором по точно тому же множеству значений — валидатор и тесты
разъехаться не могут.

> Работаете с AI-ассистентом? [llms.txt](llms.txt) — компактный API-справочник,
> написанный для LLM.

[English version](README.md)

## Зачем

Правила валидации и тестовые генераторы обычно пишутся раздельно, в разных
местах и разными версиями тебя. Они дрейфуют: консьюмер очереди принимает
`status: 'pending'`, property-тест его ни разу не порождает — и тесты слепы
к тому, что прод реально пропускает. `rasuvaeff/schema` убивает дрейф по
построению: **одна схема** задаёт множество, `parse()` проверяет его,
`arbitrary()` генерирует из него — паритет зафиксирован собственным
property-сьютом пакета на случайных деревьях схем.

Паттерн проверен в других экосистемах (pydantic + hypothesis в Python,
Zod + fast-check в TypeScript); это PHP-версия, поверх
[`rasuvaeff/property-testing-core`](https://github.com/rasuvaeff/property-testing-core).

## Требования

- PHP 8.3 / 8.4 / 8.5
- `ext-mbstring`
- `rasuvaeff/property-testing-core` ^1.2 (runtime — генераторный порт это
  продукт, не тестовая утилита)

## Установка

```bash
composer require rasuvaeff/schema
```

## Использование

### Определение

```php
use Rasuvaeff\Schema\Schema;

$user = Schema::object([
    'email' => Schema::string()->min(3)->max(254),
    'age' => Schema::int()->min(18)->max(120),
    'role' => Schema::enum(Role::class),        // backed enum
    'tags' => Schema::arrayOf(Schema::string()->min(1))->maxCount(20),
    'bio' => Schema::string()->max(1000)->default(''),   // default ≡ optional
    'meta' => Schema::mapOf(Schema::json())->optional(),
    'score' => Schema::float()->min(0.0)->max(1.0),       // только finite-числа
    'created_at' => Schema::dateTime(),                   // RFC 3339 на входе, DateTimeImmutable на выходе
]);
```

### Валидация

```php
$result = $user->parse($payload);

if (!$result->ok) {
    foreach ($result->errors as $error) {
        // '$.tags[2]' / RFC 6901 '/tags/2' — источник истины это сегменты
        $error->toPath();
        $error->toJsonPointer();
        $error->message;  // только виды и размеры, никогда само значение
    }
}

$value = $user->parseOrThrow($payload);  // SchemaException несёт все ошибки
// SchemaException наследует InvalidArgumentException — это невалидный вход,
// а не сбой рантайма. Потребители с контрактом InvalidArgumentException
// могут прокину́ть его дальше без try/catch-обёртки.
```

Семантика объекта, зафиксирована: отсутствующий optional-ключ **отсутствует
в результате** (не `null`); ключ с default всегда присутствует и несёт
проверенное, нормализованное значение (enum-default можно задать backed-значением
— хранится инстанс). `nullable()` — про значение; `optional()` — про ключ.

### Генерация

```php
use Rasuvaeff\PropertyTesting\Random;

$random = new Random(42);
$value = $user->arbitrary()->generate($random)->value;  // всегда проходит parse
```

В property-тестах (с `rasuvaeff/property-testing-testo`) схема и есть
генератор:

```php
#[Property(runs: 300)]
public function queuePayloadIsHandled(SchemaInterface $payload): void
{
    $value = Gen::draw($payload->arbitrary());
    // ...
}
```

### Экспорт

```php
$export = $user->toJsonSchema();          // strict (по умолчанию): transform
                                          // без кодека бросает исключение
$export->schema;                          // чистый фрагмент draft 2020-12
$export->nonRepresentable;                // list<NonRepresentable>: что
                                          // отброшено, с путями
$export->assertFullyRepresentable();      // кидает, перечисляя все записи

$user->toJsonSchema(lenient: true);       // transform без кодека экспортируется
                                          // как {} + запись вместо исключения
```

Refine всегда экспортируется внутренней схемой — честное надмножество,
отражённое в `nonRepresentable`. Transform с объявленным кодеком экспортирует
его — и закон 2 пакета проверяет кодек против фактического вывода генератора.
`default`-аннотация объекта проходит через wire-шов: enum-инстанс
экспортируется backed-значением.

### Wire: `toWire()`

`parse`/`arbitrary` живут в PHP-домене (enum остаётся инстансом); очереди,
HTTP и JSON-хранилища нуждаются в wire-форме. `toWire()` — проекция:
enum-инстанс становится backed-значением, контейнеры конвертируются
рекурсивно, объявленный `wire`-колбэк transform применяется, остальное
проходит насквозь.

```php
$wire = $user->toWire($parsed);   // ['role' => Role::Admin, ...] → ['role' => 'admin', ...]
```

Обратная пара генераторному домену — `arbitrary()` производит PHP-значения,
`toWire()` отдаёт их на провод. Найдено первым живым потребителем (keppio):
property-тест очереди нуждался ровно в этой проекции.

### Комбинаторы

```php
Schema::int()->min(0)->transform(static fn (int $v): string => (string) $v);
// генерация отображается в целевое пространство; shrink остаётся в исходном

Schema::int()->min(1)->nullable();   // форма-метод == Schema::nullable(...)

Schema::string()->refine(static fn (string $v): bool => !str_contains($v, ' '), 'No spaces');
// предпочитай констрейнты — refine фильтрует генератор и жжёт бюджет
```

Правило порядка: сначала комбинаторы, property-модификаторы в конце —
`->min(1)->transform(...)->optional()`.

### Гидрация в DTO: `into()`

```php
$user = Schema::object([
    'name' => Schema::string()->min(1),
    'role' => Schema::enum(Role::class),
])->into(UserDto::class);

$dto = $user->parseOrThrow($payload);   // UserDto, role уже кейс Role
$user->toWire($dto);                    // ['name' => ..., 'role' => 'admin']
```

Распарсенные свойства передаются в конструктор именованными аргументами,
уже после применения default'ов и `strip()`. DTO принимает свойства как
public promoted-аргументы конструктора — именно это делает `toWire()`
обратимым. Исключение из конструктора становится ошибкой парсинга, в которой
есть только имена классов. Экспорт — собственный экспорт object-схемы.
Держите инварианты DTO в пределах схемы: конструктор строже схемы ломает
генератор.

### Паттерны вне генерируемого подмножества: `regexUnchecked()`

`regex()` принимает только паттерны, которые умеет генерировать
`Gen::stringMatching`: `\z`, lookaround и обратные ссылки отвергаются при
построении. `regexUnchecked()` — явный выход: генератор даёте вы, схема
хранит паттерн для парсинга и экспорта.

```php
Schema::string()->regexUnchecked(
    '^a+\z',
    Gen::map(Gen::intBetween(1, 8), static fn (int $n): string => str_repeat('a', $n)),
);
```

Закон паритета теперь на вас: всё, что выдаёт генератор, обязано совпадать с
паттерном. Стройте подходящие значения конструктивно; `Gen::filter()` с
паттерном в роли предиката работает, только если совпадения часты. Экспорт
добавляет запись `regexUnchecked` в `nonRepresentable`: никто не гарантирует,
что валидатор JSON Schema (ECMA-262) прочитает PCRE-паттерн так же.

### Тестирование: `Testing\RoundTrip`

Законы паритета определены один раз рядом со схемой, а не копируются в
тестовую поддержку каждого потребителя. Не зависят от фреймворка: каждый
метод проверяет одно значение и при нарушении бросает
`Testing\RoundTripException`, так что подходят и для property-теста (подайте
`$schema->arbitrary()`), и для цикла в PHPUnit.

```php
RoundTrip::assertParses($schema, $value);          // сгенерированное ⇒ парсится
RoundTrip::assertWireRoundTrip($schema, $value);   // toWire → parse → toWire без потерь
RoundTrip::assertConsumerRoundTrip(                // ваша пара hydrate/wire
    $schema,
    $payload,
    static fn (mixed $p): object => Message::fromPayload($p),
    static fn (object $m): array => $m->toPayload(),
);
```

`assertConsumerRoundTrip()` проверяет, что `hydrate($payload)` проходит, что
`toWire($payload)` схемы совпадает с вашей wire-формой гидрированного объекта
и что wire → hydrate → wire не теряет данных. В сообщениях об ошибке — только
стадия и типы значений, никогда сами значения.

## Безопасность

Сообщения об ошибках никогда не встраивают входные значения — только виды
и размеры (`get_debug_type`, длины, количества). Payload может нести секреты,
а ошибки уезжают в логи и Sentry. Колбэк `transform`, бросивший исключение,
отдаёт только имя класса.

Строки — домен JSON: `string()` отвергает невалидный UTF-8 до всех проверок
(`mb_check_encoding`) — до длины, формата и паттерна, — поэтому длина в
code-point'ах и движок паттернов никогда не расходятся во мнениях об входе.
Сбой самого PCRE (например, исчерпан backtrack-лимит) — отдельная ошибка
`Pattern check failed (PCRE engine error: …)`, а не «строка не совпала».

## API

Фасад `Schema` — единственная точка входа; каждая фабрика возвращает
подкласс `SchemaType`, все они `@api`:

| Тип | Фабрика | Примечания |
|---|---|---|
| `Type\StringSchema` | `Schema::string()` | `min`/`max` в code-point'ах, `regex`, `regexUnchecked` (свой генератор), `format`; отвергает невалидный UTF-8 |
| `Type\IntSchema` | `Schema::int()` | `min`/`max`, `positive()` (≥1), `nonNegative()` (≥0), `coerce()` |
| `Type\FloatSchema` | `Schema::float()` | только finite (NaN/INF отвергаются); `min`/`max`, `allowInt()`, `coerce()`; экспорт `type: number` |
| `Type\DateTimeSchema` | `Schema::dateTime()` | RFC 3339 в wire ↔ `DateTimeImmutable` в домене; инклюзивные `min`/`max` |
| `Type\BoolSchema` | `Schema::bool()` | `coerce()` (`"true"`/`"1"` → `true`) |
| `Type\LiteralSchema` | `Schema::literal($v)` | строгое `===` |
| `Type\EnumSchema` | `Schema::enum(Role::class)` | backed; инстанс или backed-значение на входе, инстанс на выходе |
| `Type\JsonValueSchema` | `Schema::json()` | домен `json_decode` |
| `Type\ArraySchema` | `Schema::arrayOf($el)` | list; `minCount`/`maxCount` |
| `Type\MapSchema` | `Schema::mapOf($v)` | строковые ключи; `minCount`/`maxCount` |
| `Type\ObjectSchema` | `Schema::object([...])` | свойства `optional`/`default`, `additionalProperties()`, `strip()` |
| `Type\UnionSchema` | `Schema::union([...])` | первый успех побеждает |
| `Type\DiscriminatedSchema` | `Schema::discriminated('kind', ['a' => $obj, ...])` | тег выбирает ветвь; дизъюнктность по построению (обязательный literal-тег); экспорт `oneOf` |
| `Type\TupleSchema` | `Schema::tuple([...])` | разнородный список фиксированной длины; экспорт `prefixItems` + `items: false` |
| `Type\NullableSchema` | `Schema::nullable($s)` | null на уровне значения |
| `Type\LazySchema` | `Schema::lazy($fn)` | рекурсия, мемоизация |
| `Combinator\TransformSchema` | `->transform($fn)` | генерация в target-пространстве, shrink в исходном |
| `Combinator\RefineSchema` | `->refine($fn, $msg)` | пост-условие; фильтрованная генерация |
| `Type\ObjectProperty` | `->optional()` / `->default($v)` | property-модификаторы; default валидируется при конструировании |
| `Combinator\IntoSchema` | `Schema::object([...])->into(Dto::class)` | гидрирует распарсенные свойства в DTO (именованные аргументы); экспорт и wire — от object-схемы |

Тестовые хелперы: `Testing\RoundTrip` (`assertParses`, `assertWireRoundTrip`,
`assertConsumerRoundTrip`) и `Testing\RoundTripException`.

Результаты: `ParseResult` / `ParseError` (сегменты → `$.tags[2]` и RFC 6901),
`SchemaException`, `JsonSchemaExport` + `NonRepresentable` /
`NonRepresentableException` (строгий экспорт).

## Примеры

- [examples/queue-payload.php](examples/queue-payload.php) — схема объекта
  с enum/default, пути ошибок, генерация из той же схемы.

## Разработка

PHP/Composer на хосте нет — Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Полный набор команд и инварианты — в [AGENTS.md](AGENTS.md).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
