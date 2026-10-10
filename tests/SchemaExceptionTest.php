<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SchemaException::class)]
final class SchemaExceptionTest
{
    public function rendersEveryErrorAsPathPlusMessage(): void
    {
        $result = Schema::object([
            'name' => Schema::string()->min(2),
        ])->parse(['name' => 'x']);

        Assert::false($result->ok);

        $exception = new SchemaException($result->errors);

        Assert::string($exception->getMessage())->contains('$.name');
        Assert::same($exception->errors, $result->errors);
    }

    #[ExpectException(SchemaException::class)]
    public function parseOrThrowThrowsWithErrors(): void
    {
        Schema::string()->parseOrThrow(42);
    }
}
