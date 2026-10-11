<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests;

use Rasuvaeff\Schema\ParseError;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaException;
use Rasuvaeff\Schema\UnionBranch;
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

    public function messageRendersOneErrorPerLine(): void
    {
        $result = Schema::object([
            'a' => Schema::int(),
            'b' => Schema::int(),
        ])->parse(['a' => 'no', 'b' => 'no']);

        $exception = new SchemaException($result->errors);

        Assert::same(
            $exception->getMessage(),
            "$.a Expected int, got string\n$.b Expected int, got string",
        );
    }

    #[ExpectException(SchemaException::class)]
    public function parseOrThrowThrowsWithErrors(): void
    {
        Schema::string()->parseOrThrow(42);
    }

    public function isAnInvalidArgumentExceptionSoConsumersNeedNoWrapper(): void
    {
        try {
            Schema::object(['id' => Schema::int()->positive()])->parseOrThrow(['id' => 0]);
        } catch (SchemaException $invalid) {
            Assert::true($invalid instanceof \InvalidArgumentException);
            Assert::string($invalid->getMessage())->contains('$.id');

            return;
        }

        throw new \LogicException('parseOrThrow was expected to fail');
    }

    public function unionBranchesRenderIndented(): void
    {
        $error = new ParseError(
            ['payload'],
            'Value does not match any of 2 schemas',
            [
                new UnionBranch(0, new ParseError(['payload', 'name'], 'Expected string, got int')),
                new UnionBranch(1, new ParseError(['payload'], 'Expected int, got array')),
            ],
        );

        $exception = new SchemaException([$error]);

        Assert::same(
            $exception->getMessage(),
            "$.payload Value does not match any of 2 schemas\n"
            . "  [0] $.payload.name Expected string, got int\n"
            . '  [1] $.payload Expected int, got array',
        );
    }
}
