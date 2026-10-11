<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Schema\Schema;

enum WebhookStatus: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Retrying = 'retrying';
}

$schema = Schema::object([
    'creator_id' => Schema::string()->min(1)->max(64),
    'status' => Schema::enum(WebhookStatus::class),
    'events' => Schema::arrayOf(Schema::string()->min(1))->maxCount(10),
    'note' => Schema::string()->max(500)->default(''),
    'success_ratio' => Schema::float()->min(0.0)->max(1.0)->default(0.0),
    'created_at' => Schema::dateTime(),
]);

// 1. A valid payload parses; the missing note is filled with the default.
$valid = $schema->parse([
    'creator_id' => 'cr_123',
    'status' => 'delivered',
    'events' => ['subscription.renewed'],
    'created_at' => '2026-10-11T10:15:30.123+00:00',
]);

\printf(
    "valid: ok=%s note=%s status=%s created_at=%s\n",
    \var_export($valid->ok, true),
    $valid->value['note'],
    $valid->value['status']->name,
    $valid->value['created_at']->format(\DATE_RFC3339_EXTENDED),
);

// 2. The wire projection of the parsed value: the DateTimeImmutable
//    instance goes back to its RFC 3339 string, floats pass through.
\printf("wire: %s\n", \json_encode($schema->toWire($valid->value)));

// 2. An invalid payload reports every error with a structured path —
//    never the offending values, only kinds and sizes.
$invalid = $schema->parse([
    'creator_id' => '',
    'status' => 'unknown',
    'events' => ['ok', 42],
    'extra' => true,
    'created_at' => 'not a date',
    'success_ratio' => 2.5,
]);

\printf("invalid: ok=%s\n", \var_export($invalid->ok, true));

foreach ($invalid->errors as $error) {
    \printf("  %s %s (pointer: %s)\n", $error->toPath(), $error->message, $error->toJsonPointer());
}

// 3. The same schema is a generator: everything it produces parses —
//    the parity that keeps validators and tests from drifting apart.
$random = new Random(42);

for ($i = 0; $i < 3; $i++) {
    $value = $schema->arbitrary()->generate($random)->value;

    \printf(
        "generated #%d: parses=%s\n",
        $i,
        \var_export($schema->parse($value)->ok, true),
    );
}
