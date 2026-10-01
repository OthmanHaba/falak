# Kiln function runtime: Go (net/http handlers). See README.md for the kiln-fn-install / kiln-fn-serve /
# kiln-fn-run convention. Build context: runtimes/functions (this file: go/Dockerfile).
FROM golang:1.27.1-alpine AS installer

WORKDIR /src
COPY go/install/ .
RUN CGO_ENABLED=0 go test ./... && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /kiln-fn-install .

FROM golang:1.27.1-alpine

# git for modules that are not on the module proxy.
RUN apk add --no-cache git ca-certificates

COPY --from=installer /kiln-fn-install /usr/local/bin/kiln-fn-install
COPY go/runtime/kiln_runtime.go /opt/kiln-fn/go/kiln_runtime.go
COPY --chmod=0755 go/bin/kiln-fn-serve go/bin/kiln-fn-run /usr/local/bin/

# The root filesystem is read-only at run time. The install writes only to /app (go.mod, go.sum, .kiln/fn), /cache
# (modules and the build cache, shared by every Go function on the server) and /tmp. The binary is static, so
# serving needs neither the toolchain nor /cache.
ENV CGO_ENABLED=0 \
    GOFLAGS=-mod=mod \
    GOTOOLCHAIN=local \
    GOTELEMETRY=off \
    GOPATH=/tmp/go \
    GOMODCACHE=/cache/go/mod \
    GOCACHE=/cache/go/build \
    HOME=/tmp \
    TMPDIR=/tmp \
    PORT=8080 \
    KILN_ENTRYPOINT=main.go

WORKDIR /app
USER 65534:65534
EXPOSE 8080
CMD ["kiln-fn-serve"]
