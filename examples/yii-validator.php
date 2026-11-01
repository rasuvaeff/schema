<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Schema\Bridge\YiiValidator\SchemaRule;
use Rasuvaeff\Schema\Schema;
use Yiisoft\Validator\Rule\Required;
use Yiisoft\Validator\Validator;

// Needs yiisoft/validator (a suggested dependency; installed here as a dev one).
$order = Schema::object([
    'items' => Schema::arrayOf(Schema::object([
        'sku' => Schema::string()->min(1),
        'qty' => Schema::int()->positive(),
    ]))->minCount(1),
]);

$result = (new Validator())->validate(
    ['customer' => 'ann', 'payload' => ['items' => [['sku' => 'A-1', 'qty' => 2], ['sku' => '', 'qty' => 0]]]],
    [
        'customer' => [new Required()],
        'payload' => [new SchemaRule($order)],
    ],
);

foreach ($result->getErrorMessagesIndexedByPath() as $path => $messages) {
    echo $path, ': ', implode('; ', $messages), PHP_EOL;
}
