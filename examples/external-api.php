<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Unknown;

// A third-party payment answer: read what you need, ignore the rest.
$payment = Schema::object([
    'id' => Schema::string()->min(1),
    'state' => Schema::literals(['pending', 'succeeded', 'failed']),
    'amountCents' => Schema::int()->nonNegative(),
    'receiptUrl' => Schema::string()->optional(),
    'receiptEmail' => Schema::string()->optional(),
], unknown: Unknown::Strip)->requireAnyOf('receiptUrl', 'receiptEmail');

// 1. A good answer; the unknown `livemode` is dropped.
$ok = $payment->parseJson('{"id": "pay_1", "state": "succeeded", "amountCents": 990, "receiptUrl": "https://example.com/r/1", "livemode": false}');
echo 'ok: ', json_encode($ok->orThrow(), JSON_THROW_ON_ERROR), PHP_EOL;

// 2. A changed answer: a new state and neither receipt field.
$changed = $payment->parseJson('{"id": "pay_2", "state": "requires_action", "amountCents": 990}');
echo 'changed: ', $changed->summary(), PHP_EOL;

// 3. Not JSON at all: an error at the root, not a type error about null.
echo 'garbage: ', $payment->parseJson('<html>502 Bad Gateway</html>')->summary(), PHP_EOL;

// 4. A PATCH body over the same shape: every key optional.
$patch = $payment->pick('state', 'amountCents')->partial();
echo 'patch: ', json_encode($patch->parseJson('{"state": "failed"}')->orThrow(), JSON_THROW_ON_ERROR), PHP_EOL;
