# rasuvaeff/schema

![License](https://img.shields.io/packagist/l/rasuvaeff/schema) ![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/schema/php)

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

### Wire (внутренний шов)

`parse`/`arbitrary` живут в PHP-домене (enum остаётся инстансом); экспорт и
его закон паритета — в JSON-домене. Проекция — enum → backed-значение,
контейнеры рекурсивно, объявленный `wire`-колбэк transform'а — это
внутренний шов `Wire`; публикация его как порта `serialize()` — план после
первого пилота у потребителя.

### Комбинаторы

```php
Schema::int()->min(0)->transform(static fn (int $v): string => (string) $v);
// генерация отображается в целевое пространство; shrink остаётся в исходном

Schema::string()->refine(static fn (string $v): bool => !str_contains($v, ' '), 'No spaces');
// предпочитай констрейнты — refine фильтрует генератор и жжёт бюджет
```

Правило порядка: сначала комбинаторы, property-модификаторы в конце —
`->min(1)->transform(...)->optional()`.

## Безопасность

Сообщения об ошибках никогда не встраивают входные значения — только виды
и размеры (`get_debug_type`, длины, количества). Payload может нести секреты,
а ошибки уезжают в логи и Sentry. Колбэк `transform`, бросивший исключение,
отдаёт только имя класса.

## API

Фасад `Schema` — единственная точка входа; каждая фабрика возвращает
подкласс `SchemaType`, все они `@api`:

| Тип | Фабрика | Примечания |
|---|---|---|
| `Type\StringSchema` | `Schema::string()` | `min`/`max` в code-point'ах, `regex`, `format` |
| `Type\IntSchema` | `Schema::int()` | `min`/`max` |
| `Type\BoolSchema` | `Schema::bool()` | |
| `Type\LiteralSchema` | `Schema::literal($v)` | строгое `===` |
| `Type\EnumSchema` | `Schema::enum(Role::class)` | backed; инстанс или backed-значение на входе, инстанс на выходе |
| `Type\JsonValueSchema` | `Schema::json()` | домен `json_decode` |
| `Type\ArraySchema` | `Schema::arrayOf($el)` | list; `minCount`/`maxCount` |
| `Type\MapSchema` | `Schema::mapOf($v)` | строковые ключи; `minCount`/`maxCount` |
| `Type\ObjectSchema` | `Schema::object([...])` | свойства `optional`/`default`, `additionalProperties()` |
| `Type\UnionSchema` | `Schema::union([...])` | первый успех побеждает |
| `Type\NullableSchema` | `Schema::nullable($s)` | null на уровне значения |
| `Type\LazySchema` | `Schema::lazy($fn)` | рекурсия, мемоизация |
| `Combinator\TransformSchema` | `->transform($fn)` | генерация в target-пространстве, shrink в исходном |
| `Combinator\RefineSchema` | `->refine($fn, $msg)` | пост-условие; фильтрованная генерация |
| `Type\ObjectProperty` | `->optional()` / `->default($v)` | property-модификаторы; default валидируется при конструировании |

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
