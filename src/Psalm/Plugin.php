<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Psalm;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

/**
 * Psalm plugin: `Schema::object([...])` infers `ObjectSchema<array{...}>`,
 * so `parse()->value`, `parseOrThrow()` and `orThrow()` return the exact
 * array shape instead of `array<string, mixed>` — no `@var` after a parse.
 *
 * ```xml
 * <plugins>
 *     <pluginClass class="Rasuvaeff\Schema\Psalm\Plugin"/>
 * </plugins>
 * ```
 *
 * or `vendor/bin/psalm-plugin enable rasuvaeff/schema`. Requires
 * `vimeo/psalm` ^6 (a suggested dependency).
 *
 * @api
 */
final readonly class Plugin implements PluginEntryPointInterface
{
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        // Psalm only registers hooks of classes that are already loaded.
        \class_exists(ObjectShapeProvider::class);
        $registration->registerHooksFromClass(ObjectShapeProvider::class);
    }
}
