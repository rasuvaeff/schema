<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Benchmarks;

use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\ObjectSchema;
use Testo\Bench;

final class SchemaBench
{
    private static ?ObjectSchema $schema = null;

    #[Bench(
        callables: [
            'baseline' => [self::class, 'baselineCall'],
        ],
        calls: 10_000,
        iterations: 10,
    )]
    public static function parseObject(): bool
    {
        return self::schema()->parse(self::payload())->ok;
    }

    public static function baselineCall(): bool
    {
        return true;
    }

    private static function schema(): ObjectSchema
    {
        if (self::$schema === null) {
            self::$schema = Schema::object([
                'email' => Schema::string()->min(3)->max(254),
                'age' => Schema::int()->min(18)->max(120),
                'tags' => Schema::arrayOf(Schema::string()->min(1))->maxCount(20),
                'bio' => Schema::string()->max(1000)->default(''),
            ]);
        }

        return self::$schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'email' => 'user@example.com',
            'age' => 30,
            'tags' => ['alpha', 'beta'],
        ];
    }
}
