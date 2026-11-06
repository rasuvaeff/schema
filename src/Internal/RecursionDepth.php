<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Internal;

/**
 * Process-local depth of the ongoing recursive generation through
 * {@see \Rasuvaeff\Schema\Type\LazySchema}. Sequential by design, like the
 * property-testing engine's own process-local statics: enter/exit bracket
 * every nested generate() call and always balance.
 *
 * Containers built inside the recursion read the remaining budget and shrink
 * their size range with it, so the branching process stays subcritical: a
 * node near the bottom of the budget cannot spawn enough recursive children
 * to keep every path growing. Without this, an ordinary tree (an optional
 * array of nodes with several elements each) is supercritical — the expected
 * number of recursive children per node is above 1 — and most random draws
 * never reach a leaf within the budget.
 *
 * @internal
 */
final class RecursionDepth
{
    /** Total nesting levels a recursive generation may use. */
    public const int BUDGET = 32;

    /** Divide the remaining budget by this to get a container's max size. */
    private const int SIZE_DIVISOR = 8;

    private static int $depth = 0;

    public static function enter(): void
    {
        self::$depth++;
    }

    public static function exit(): void
    {
        self::$depth--;
    }

    public static function remaining(): int
    {
        return max(0, self::BUDGET - self::$depth);
    }

    /**
     * The effective max size for a container (array/map) of recursive
     * elements: never above the declared one, never below the declared
     * minimum, and shrinking to nothing as the budget runs out. Inputs are
     * clamped to non-negative — the schema types validate counts at
     * construction, but their properties carry plain nullable ints.
     */
    public static function containerMax(int $declaredMin, int $declaredMax): int
    {
        if (self::$depth === 0) {
            return max(0, $declaredMax);
        }

        $capped = intdiv(self::remaining(), self::SIZE_DIVISOR);

        /** @var int<0, max> */
        return max(0, $declaredMin, min($declaredMax, $capped));
    }
}
