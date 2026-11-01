<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Combinator\IntoSchema;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Testing\RoundTrip;
use Rasuvaeff\Schema\Unknown;

enum Currency: string
{
    case Eur = 'EUR';
    case Usd = 'USD';
}

/**
 * One schema is the parser, the serializer and the generator of the event.
 */
final readonly class OrderPaid
{
    public function __construct(
        public string $orderId,
        public int $amountCents,
        public Currency $currency,
        public \DateTimeImmutable $paidAt,
    ) {}

    /**
     * @return IntoSchema<self>
     */
    public static function schema(): IntoSchema
    {
        return Schema::object([
            'orderId' => Schema::string()->min(1)->max(64),
            'amountCents' => Schema::int()->nonNegative(),
            'currency' => Schema::enum(Currency::class),
            'paidAt' => Schema::dateTime(),
        ], unknown: Unknown::Strip)->into(self::class);
    }

    public static function fromJson(string $json): self
    {
        return self::schema()->parseJson($json)->orThrow();
    }

    public function toJson(): string
    {
        return json_encode(self::schema()->toWire($this), JSON_THROW_ON_ERROR);
    }
}

// 1. The consumer side: JSON from the queue → a typed event. An unknown key
//    from a newer producer is stripped, not an error.
$event = OrderPaid::fromJson('{"orderId": "o-1", "amountCents": 1250, "currency": "EUR", "paidAt": "2026-11-01T10:00:00.000+00:00", "addedLater": true}');
echo 'parsed: ', $event->orderId, ' ', $event->amountCents, ' ', $event->currency->name, PHP_EOL;

// 2. The producer side: the same schema writes the wire form.
echo 'wire: ', $event->toJson(), PHP_EOL;

// 3. A broken message: every problem with its path, never the values.
$broken = OrderPaid::schema()->parseJson('{"orderId": "", "amountCents": -5, "currency": "GBP"}');
echo 'broken: ', $broken->summary(limit: 5), PHP_EOL;

// 4. The property test, once per message type: generated events survive
//    toWire → parse → toWire unchanged.
$random = new Random(7);

for ($i = 0; $i < 100; $i++) {
    RoundTrip::assertWireRoundTrip(OrderPaid::schema(), OrderPaid::schema()->arbitrary()->generate($random)->value);
}

echo 'round trip: 100 generated events OK', PHP_EOL;
