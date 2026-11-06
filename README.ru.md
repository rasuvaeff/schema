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
    'state' => Schema::literals(['pending', 'paid']),     // набор значений без PHP-enum
]);
```

### Валидация

```php
$result = $user->parse($payload);

if (!$result->ok) {
    foreach ($result->errors as $error) {
        // '$.tags[2]' / RFC 6901 '/tags/2' — источник истины это сегменты;
        // ключ, который не простое имя, берётся в кавычки: $['user@example.com'], $['a.b']
        $error->toPath();
        $error->toJsonPointer();
        $error->message;  // только виды и размеры, никогда само значение
    }
}

$value = $user->parseOrThrow($payload);  // SchemaException несёт все ошибки
// SchemaException наследует InvalidArgumentException — это невалидный вход,
// а не сбой рантайма. Потребители с контрактом InvalidArgumentException
// могут прокину́ть его дальше без try/catch-обёртки.

$result = $user->parseJson($body);       // decode + parse; невалидный JSON —
                                         // ошибка в '$', а не ошибка типа про null
$value = $result->orThrow();             // значение или SchemaException
$result->summary();                      // '$.age Int must be at least 18; $.tags[2] …'
                                         // первые 3 ошибки + '(+N more)'; '' при успехе
(string) $error;                         // '$.tags[2] Expected string, got int'
```

Семантика объекта, зафиксирована: отсутствующий optional-ключ **отсутствует
в результате** (не `null`); ключ с default всегда присутствует и несёт
проверенное, нормализованное значение (enum-default можно задать backed-значением
— хранится инстанс). `nullable()` — про значение; `optional()` — про ключ.

### Неизвестные ключи

У каждого объекта один режим для необъявленных ключей — кейс `Unknown`:

| Режим | Неизвестный ключ | Экспорт | Для чего |
|---|---|---|---|
| `Unknown::Reject` (по умолчанию) | ошибка разбора по его пути | `additionalProperties: false` | payload'ы, которыми вы владеете |
| `Unknown::Strip` | выбрасывается из результата | `additionalProperties: true` + запись `strip` | чужие ответы, которые читаются частично |
| `Unknown::Passthrough` | остаётся в результате как есть | `additionalProperties: true` | map'ы, которые гоняются туда-обратно |

```php
use Rasuvaeff\Schema\Unknown;

Schema::object(['id' => Schema::int()], unknown: Unknown::Strip);
$schema->unknown(Unknown::Passthrough);   // та же форма, другой режим
```

Режим принадлежит одному объекту: у вложенного объекта свой (по умолчанию —
reject).

### Композиция объектов

```php
$order = Schema::object([
    'id' => Schema::int()->positive(),
    'email' => Schema::string()->optional(),
    'phone' => Schema::string()->optional(),
])->requireAnyOf('email', 'phone');   // хотя бы один есть; вызовы накапливаются

$order->extend(['note' => Schema::string()->optional()]);   // добавить или заменить
$order->pick('id', 'email');                                // оставить только эти
$order->omit('phone');                                      // убрать эти
$order->partial();                                          // все ключи optional (тело PATCH)
```

Режим неизвестных ключей сохраняется на каждом шаге. `pick()`/`omit()`
убирают выброшенные имена из групп `requireAnyOf`, опустевшая группа
исчезает; необъявленное имя — `InvalidArgumentException`. Свойство с default
удовлетворяет группу (оно всегда присутствует). Генератор удовлетворяет группы
конструктивно; экспорт добавляет `anyOf: [{required: [email]}, {required: [phone]}]`,
кроме группы со свойством с default: она ничего не ограничивает и в экспорт не
попадает.

### Типизированный результат: Psalm-плагин

Без плагина объект разбирается в `array<string, mixed>`, и потребитель пишет
`/** @var array{...} */` после каждого разбора. Плагин выводит форму из
объявления:

```xml
<!-- psalm.xml -->
<plugins>
    <pluginClass class="Rasuvaeff\Schema\Psalm\Plugin"/>
</plugins>
```

```php
$payment = Schema::object([
    'state' => Schema::literals(['pending', 'succeeded']),
    'amountCents' => Schema::int()->nonNegative(),
    'reference' => Schema::string()->optional(),
], unknown: Unknown::Strip)->parseJson($body)->orThrow();
// array{state: 'pending'|'succeeded', amountCents: int, reference?: string}
```

| Объявление | Ключ в форме |
|---|---|
| голая схема, `->default($v)` | `name: T` (всегда есть) |
| `->optional()` | `name?: T` |
| переменная с `ObjectProperty` | `name?: T` (плагин не видит, есть ли default) |
| `Unknown::Passthrough` | форма остаётся открытой: `...<string, mixed>` |

`extend()`, `pick()`, `omit()`, `partial()` и `unknown()` переносят форму;
`into(Dto::class)` и без плагина даёт тип DTO. Значения union, tuple,
discriminated, lazy и json остаются `mixed` / `list<mixed>` /
`array<string, mixed>`. Объявление, которое плагин не может прочитать
(свойства собраны в цикле), откатывается к `array<string, mixed>`. Нужен
`vimeo/psalm` ^7.

`$result->value` тоже несёт форму (`array{...}|null`, null при неуспехе), но
psalm не сужает его по `$result->ok`. Типизированный доступ — `orThrow()`
(с исключением) или `valueOr($default)` (без исключения):
`$user = $result->valueOr(null); if ($user === null) { … }` оставляет `$user`
точным `array{...}`. Ограничения в выведенный тип не входят:
`int()->min(0)` — это `int`, а не `int<0, max>`, `string()->min(1)` —
`string`, а не `non-empty-string`; где это важно, сужайте вручную. Схема,
которую возвращает вспомогательный метод, теряет выведенную форму на границе
метода — объявите её там: `/** @return ObjectSchema<array{id: int, name?: string}> */`.

### Рецепт: событие — это одна схема

Типу сообщения нужны парсер (`fromPayload`), сериализатор (`toPayload`) и
генератор для property-тестов. С `into()` и `toWire()` схема — это все три,
а `parse()` и есть `fromWire()`:

```php
final readonly class OrderPaid
{
    public function __construct(public string $orderId, public Currency $currency) {}

    /** @return IntoSchema<self> */
    public static function schema(): IntoSchema
    {
        return Schema::object([
            'orderId' => Schema::string()->min(1),
            'currency' => Schema::enum(Currency::class),
        ], unknown: Unknown::Strip)->into(self::class);
    }

    public static function fromJson(string $json): self
    {
        return self::schema()->parseJson($json)->orThrow();
    }

    public function toPayload(): array
    {
        return self::schema()->toWire($this);   // ['orderId' => ..., 'currency' => 'EUR']
    }
}

// property-тест, один раз на тип сообщения:
$event = Gen::draw(OrderPaid::schema()->arbitrary());
RoundTrip::assertWireRoundTrip(OrderPaid::schema(), $event);
```

Рукописный `toPayload()` не может разойтись с парсером: это одно и то же
дерево. `toWire()` у схемы `into()` (и у объекта) типизирован как
`array<string, mixed>`, так что `toPayload(): array` обходится без каста —
если `schema()` объявлен как `IntoSchema` (`@return IntoSchema<self>`).
Интерфейс с `schema(): SchemaInterface` можно реализовать с более узким
`IntoSchema`; через сам интерфейс `toWire()` снова `mixed`.
Выигрыш тем больше, чем больше полей в payload; для одного поля литеральный
массив не хуже.

`parseJson()` сообщает о невалидном JSON ошибкой разбора, поэтому
`JsonException` нигде не прикладывается; где вызывающему нужна именно эта
причина, оставьте `json_decode(..., JSON_THROW_ON_ERROR)`. `toWire()` поверх `into()` читает публичные свойства DTO
(`get_object_vars()`), поэтому DTO принимает их promoted-аргументами
конструктора. См. [examples/event-roundtrip.php](examples/event-roundtrip.php).

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

Рекурсивные схемы на `Schema::lazy()` тоже генерируются: рекурсия
развёртывается в момент `generate()` (а не при построении генератора),
глубина вложенности ограничена бюджетом в 32 уровня. Схема, у которой
каждый путь через рекурсию обязателен (нет ни опционального ключа, ни
пустого массива, ни нерекурсивной ветки union), конечного значения не
имеет вовсе — её генератор падает с `GenerationExhaustedException`, а не
съедает память.

Предпочитайте точные генераторы `->refine()`: refinement — это
`Gen::filter`, и широкий источник с редким предикатом (любая строка,
которая обязана быть безопасным URL) сжигает бюджет попыток фильтра.
Когда можно, закладывайте ограничение в саму схему (`->regex()`,
`->format()`, `Schema::literals()`).

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
уже после применения default'ов и `Unknown::Strip`. DTO принимает свойства как
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
стадия и типы значений, никогда сами значения. `assertWireRoundTrip()` требует
обратимой схемы: transform без обратной функции `wire` или union с
перекрывающимися членами (выигрывает первый подошедший, и wire-форма может
распарситься через другой член) ломают закон по построению — предпочитайте
`Schema::discriminated()`.

### Мост к `yiisoft/validator`

Схема как правило валидатора: значение свойства обязано разбираться, каждая
ошибка схемы попадает по своему пути под свойством.

```php
use Rasuvaeff\Schema\Bridge\YiiValidator\SchemaRule;
use Yiisoft\Validator\Validator;

$result = (new Validator())->validate($data, [
    'payload' => [new SchemaRule(Schema::object([
        'items' => Schema::arrayOf(Schema::object(['sku' => Schema::string()->min(1)])),
    ]))],
]);

$result->getErrorMessagesIndexedByPath();
// ['payload.items.1.sku' => ['String length must be at least 1, got 0']]
```

`skipOnEmpty`, `skipOnError` и `when` работают как у любого правила
`yiisoft/validator`. Параметры ошибки несут `path` (`$.items[1].sku`) и
`pointer` (`/items/1/sku`). Сообщения добавляются без перевода и
форматирования: это английские сообщения схемы, в них нет входных значений, и
в них могут быть фигурные скобки (значение литерала), которые форматтер принял
бы за плейсхолдеры. Нужен `yiisoft/validator` ^2.6.

Мост к Respect не поставляется: `respect/validation` 3 требует PHP 8.5, а пакет
поддерживает 8.3. Для Respect внутри yiisoft/validator есть
[`rasuvaeff/yii3-respect-validation`](https://github.com/rasuvaeff/yii3-respect-validation).

## Контракт стабильности

Что 1.x сохраняет (изменение — мажорный релиз):

- фасад и каждый `@api`-тип, метод и публичное свойство;
- семантику разбора: принимаемые и отклоняемые входы, нормализацию (enum →
  инстанс, `allowInt` → float, `coerce` → типизированное значение, default
  присутствуют, отсутствующие optional-ключи отсутствуют, порядок ключей
  объекта как в объявлении), три режима `Unknown`, первый успех у `union`,
  выбор ветки по тегу у `discriminated`;
- **пути** ошибок (`ParseError::$segments`, `toPath()` с ключами в кавычках,
  если это не простые имена, `toJsonPointer()`) и правило, что в сообщениях нет
  входных значений (ключи — только в путях);
- закон паритета (всё, что генерирует `arbitrary()`, разбирается) и
  wire-закон (выход `toWire()` проходит валидацию по `toJsonSchema()`);
- `toWire()` как обратную к `parse()`: enum → backed-значение, `dateTime` →
  RFC 3339 с миллисекундами, `into()` → wire-форма объекта, объявленный
  `wire` у transform;
- диалект JSON Schema (draft 2020-12) и список конструкций
  `NonRepresentable`: `refine`, `transform` (без кодека, lenient), `strip`,
  `coerce`, `regexUnchecked`, `dateTime bounds`;
- контракт strict/lenient-экспорта.

Что может меняться в минорном релизе:

- **тексты** сообщений (сопоставлять по путям и `segments`, не по формулировке);
- точный фрагмент JSON Schema для того же множества (порядок ключевых слов,
  добавленная аннотация);
- распределение и shrinking сгенерированных значений (множество то же);
- всё `@internal` (`Internal\*`, класс-провайдер Psalm-плагина).

## Безопасность

Сообщения об ошибках никогда не встраивают входные значения — только виды
и размеры (`get_debug_type`, длины, количества). **Ключи** входа — это
структура, а не значения: они есть в пути ошибки (`$.extra`,
`$['user@example.com']`), но не в сообщении (`Unknown property`), так что если
секретными могут быть сами ключи, маскировать нужно путь. Payload может нести секреты,
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
| `Type\LiteralsSchema` | `Schema::literals(['a', 'b'])` | одно из набора, строгое `===`; экспорт `enum` |
| `Type\EnumSchema` | `Schema::enum(Role::class)` | backed; инстанс или backed-значение на входе, инстанс на выходе |
| `Type\JsonValueSchema` | `Schema::json()` | домен `json_decode` |
| `Type\ArraySchema` | `Schema::arrayOf($el)` | list; `minCount`/`maxCount` |
| `Type\MapSchema` | `Schema::mapOf($v)` | строковые ключи; `minCount`/`maxCount` |
| `Type\ObjectSchema` | `Schema::object([...], unknown: Unknown::Strip)` | свойства `optional`/`default`; `unknown()`, `requireAnyOf()`, `extend()`, `pick()`, `omit()`, `partial()`, `into()` |
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

Результаты: `ParseResult` (`orThrow()`, `summary()`) / `ParseError` (сегменты →
`$.tags[2]` и RFC 6901, `Stringable`), `SchemaException`, `JsonSchemaExport` +
`NonRepresentable` / `NonRepresentableException` (строгий экспорт). У каждой
схемы есть `parse()`, `parseJson()`, `parseOrThrow()`, `arbitrary()`,
`toJsonSchema()`, `toWire()`.

Режимы неизвестных ключей: `Unknown` (`Reject`, `Strip`, `Passthrough`).

Интеграции (необязательные зависимости): `Psalm\Plugin` (вывод формы, нужен
`vimeo/psalm`), `Bridge\YiiValidator\SchemaRule` + `SchemaRuleHandler`
(нужен `yiisoft/validator`).

## Примеры

- [examples/queue-payload.php](examples/queue-payload.php) — схема объекта
  с enum/default, пути ошибок, генерация из той же схемы.
- [examples/event-roundtrip.php](examples/event-roundtrip.php) — класс
  события, у которого парсер, сериализатор и генератор — одна схема.
- [examples/external-api.php](examples/external-api.php) — ответ чужого API:
  `parseJson()`, `Unknown::Strip`, `literals()`, `requireAnyOf()`, `summary()`.
- [examples/yii-validator.php](examples/yii-validator.php) — схема как правило
  `yiisoft/validator`.

## Разработка

PHP/Composer на хосте нет — Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Полный набор команд и инварианты — в [AGENTS.md](AGENTS.md).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
