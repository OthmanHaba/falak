# Kiln function runtime: Go

The image Kiln runs Go functions with (`fn.release.apply` → `kiln-fn-gateway`). Based on
`golang:<version>-alpine`. Build context: `runtimes/functions`, file `go/Dockerfile`.

```go
package main

import (
	"context"
	"net/http"
)

// Handler serves HTTP: an http.Handler (a ServeMux, a router) or a func(http.ResponseWriter, *http.Request).
var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /hello/{name}", func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("Hello, " + r.PathValue("name") + "!"))
	})
	return mux
}

// Scheduled is optional: it runs on the function's schedules. An error or a panic marks the run failed.
func Scheduled(ctx context.Context, event Event) error { return nil }
```

## Convention

The same commands as every function runtime (see `../bun/README.md`). The agent and the gateway only rely on them.

| Command | Does |
|---|---|
| `kiln-fn-install` | Checks the package of `$KILN_ENTRYPOINT` (default `main.go`): `package main`, a `Handler` and/or `Scheduled`, no `main()`. In a copy under `/tmp`, it adds the runtime (`runtime/kiln_runtime.go`) and a generated `main()`, runs `go mod tidy` (creating `go.mod` as module `function` when there is none) and `go build`s a static binary into `/app/.kiln/fn`. `go.mod` and `go.sum` are copied back to `/app`. |
| `kiln-fn-serve` | Runs `/app/.kiln/fn`: `Handler` on `0.0.0.0:$PORT`. It listens once package initialisation is done, so an accepted connection means ready. A panic answers 500 instead of dropping the connection. SIGTERM shuts down gracefully (10 s). |
| `kiln-fn-run` | Runs `/app/.kiln/fn run`: calls `Scheduled(ctx, event)` once; exit 0 when it returns nil, 1 on an error or a panic. `ctx` is cancelled on SIGTERM (the run's timeout). |

**`Event`** is declared by the runtime: `Name`, `Schedule`, `Cron`, `Trigger` (`"cron"` or `"manual"`) and
`ScheduledTime`. Don't declare `main`, `Event`, or names starting with `kiln` in the function's package.

**Several files:** every `.go` file next to the entrypoint is part of `package main`; folders are packages of the
module (`"function/lib"` for `lib/` without your own `go.mod`).

## Modules and builds

- `go mod tidy` resolves what the code imports (module proxy, `git` for the rest). The agent keeps the generated
  `go.mod` and `go.sum` per code version and puts them back for later releases of the same code, so a redeploy or a
  rollback builds with exactly the same module versions. A `go.mod` among the function's files is used as is.
- `GOMODCACHE` and `GOCACHE` live in `/cache` (shared by the Go functions on the server), so rebuilds are quick.
- `CGO_ENABLED=0`, `-trimpath -ldflags="-s -w"`: the binary is static and small (≈7 MB for a ServeMux API).
  Serving needs neither the toolchain nor `/cache`, and the root filesystem stays read-only.

## Telemetry

When `$KILN_OTLP_SOCKET` exists, the runtime reports to it (standard library only), encoded like the other runtimes
and following `contracts/telemetry/README.md`:

- **Requests:** a `request` span per request, named by the `ServeMux` pattern (`GET /hello/{name}`; `GET /{$}` is
  `/`). A `ServeMux` that matches nothing gives `(unmatched)`; other handlers get the path with ids replaced
  (`/users/:id`). Status code, `faas.coldstart` (from `X-Kiln-Cold-Start`) and a W3C `traceparent` parent.
- **Panics:** an `exception` event with the stack, ERROR status, and a 500.
- **Outgoing HTTP:** `http.DefaultTransport` is wrapped: calls made with the request's context
  (`http.NewRequestWithContext(r.Context(), …)`) or the `ctx` of `Scheduled` become `outgoing_request` spans. Query
  strings are not recorded. Clients with their own `http.Transport` are not traced.
- **Scheduled runs:** a `scheduled_task` span per run, `finished` or `failed` (with the error).

Spans are batched (every second, or 256 at a time) and never block a request; they are dropped when the socket is
down. Set `KILN_TELEMETRY=off` to turn it off.

## Build and try

```sh
docker build -t kiln-fn-go:dev -f runtimes/functions/go/Dockerfile runtimes/functions
mkdir -p /tmp/fn/app /tmp/fn/cache && cp main.go /tmp/fn/app/ && chmod -R a+rwX /tmp/fn
docker run --rm --read-only --tmpfs /tmp --user 65534:65534 -v /tmp/fn/app:/app -v /tmp/fn/cache:/cache \
  kiln-fn-go:dev kiln-fn-install
docker run --rm --read-only --tmpfs /tmp --user 65534:65534 -v /tmp/fn/app:/app:ro -p 127.0.0.1:8080:8080 \
  kiln-fn-go:dev
```
