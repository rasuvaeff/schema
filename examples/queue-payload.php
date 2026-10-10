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
]);

// 1. A valid payload parses; the missing note is filled with the default.
$valid = $schema->parse([
    'creator_id' => 'cr_123',
    'status' => 'delivered',
    'events' => ['subscription.renewed'],
]);

\printf("valid: ok=%s note=%s status=%s\n", \var_export($valid->ok, true), $valid->value['note'], $valid->value['status']->name);

// 2. An invalid payload reports every error with a structured path —
//    never the offending values, only kinds and sizes.
$invalid = $schema->parse([
    'creator_id' => '',
    'status' => 'unknown',
    'events' => ['ok', 42],
    'extra' => true,
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
