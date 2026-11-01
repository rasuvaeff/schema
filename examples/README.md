# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `queue-payload.php` | Object schema with enum/default/optional, error paths (`$.events[1]` + RFC 6901 pointer), generation from the same schema (`Random` seed) | No |
| `event-roundtrip.php` | An event class whose parser (`parseJson()->orThrow()`), serializer (`toWire()`) and generator are one `into()` schema; `Testing\RoundTrip` over 100 generated events | No |
| `external-api.php` | A third-party answer: `parseJson()`, `Unknown::Strip`, `literals()`, `requireAnyOf()`, `summary()`, `pick()`/`partial()` for a PATCH body | No |
| `yii-validator.php` | A schema as a `yiisoft/validator` rule: errors at `payload.items.1.sku` | No (needs `yiisoft/validator`, a dev dependency here) |

Run (no PHP on the host — Docker, as everywhere in this monorepo):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/queue-payload.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/event-roundtrip.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/external-api.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/yii-validator.php
```

The scripts need `vendor/` — run `composer install` first (same container).
