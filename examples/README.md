# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `queue-payload.php` | Object schema with enum/default/optional, error paths (`$.events[1]` + RFC 6901 pointer), generation from the same schema (`Random` seed) | No |

Run (no PHP on the host — Docker, as everywhere in this monorepo):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/queue-payload.php
```

The script needs `vendor/` — run `composer install` first (same container).
