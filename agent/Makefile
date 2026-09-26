# kiln-agent build. Zero CGO, static, stripped.
VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -buildid= -X github.com/kiln/agent/internal/version.Version=$(VERSION)
GOFLAGS := -trimpath
PLATFORMS := linux/amd64 linux/arm64

.PHONY: build test vet clean sizes

build: $(foreach p,$(PLATFORMS),bin/kiln-agent-$(subst /,-,$(p)))
	@$(MAKE) --no-print-directory sizes

bin/kiln-agent-%: FORCE
	@mkdir -p bin
	CGO_ENABLED=0 GOOS=$(word 1,$(subst -, ,$*)) GOARCH=$(word 2,$(subst -, ,$*)) \
		go build $(GOFLAGS) -ldflags '$(LDFLAGS)' -o $@ ./cmd/kiln-agent

sizes:
	@ls -l bin/ | awk 'NR>1 {printf "%-28s %6.2f MB\n", $$9, $$5/1048576}'

test:
	go test ./...

vet:
	go vet ./...

clean:
	rm -rf bin

FORCE:
