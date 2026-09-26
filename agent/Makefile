# kiln-agent (servers), kiln (CLI), kiln-builder (build worker). Zero CGO, static, stripped.
VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -buildid= -X github.com/kiln/agent/internal/version.Version=$(VERSION)
GOFLAGS := -trimpath

AGENT_PLATFORMS   := linux-amd64 linux-arm64
CLI_PLATFORMS     := darwin-amd64 darwin-arm64 linux-amd64 linux-arm64
BUILDER_PLATFORMS := linux-amd64 linux-arm64

BINARIES := $(foreach p,$(AGENT_PLATFORMS),bin/kiln-agent-$(p)) \
            $(foreach p,$(CLI_PLATFORMS),bin/kiln-$(p)) \
            $(foreach p,$(BUILDER_PLATFORMS),bin/kiln-builder-$(p))

.PHONY: build agent cli builder test vet clean sizes

build: $(BINARIES)
	@$(MAKE) --no-print-directory sizes

agent: $(foreach p,$(AGENT_PLATFORMS),bin/kiln-agent-$(p))
cli: $(foreach p,$(CLI_PLATFORMS),bin/kiln-$(p))
builder: $(foreach p,$(BUILDER_PLATFORMS),bin/kiln-builder-$(p))

# $(call gobuild,<cmd dir>,<os-arch>)
define gobuild
	@mkdir -p bin
	CGO_ENABLED=0 GOOS=$(word 1,$(subst -, ,$(2))) GOARCH=$(word 2,$(subst -, ,$(2))) \
		go build $(GOFLAGS) -ldflags '$(LDFLAGS)' -o $@ ./cmd/$(1)
endef

# Static pattern rules: kiln-builder-* and kiln-agent-* must not fall into the kiln-% CLI rule.
$(foreach p,$(AGENT_PLATFORMS),bin/kiln-agent-$(p)): bin/kiln-agent-%: FORCE
	$(call gobuild,kiln-agent,$*)

$(foreach p,$(BUILDER_PLATFORMS),bin/kiln-builder-$(p)): bin/kiln-builder-%: FORCE
	$(call gobuild,kiln-builder,$*)

$(foreach p,$(CLI_PLATFORMS),bin/kiln-$(p)): bin/kiln-%: FORCE
	$(call gobuild,kiln,$*)

sizes:
	@ls -l bin/ | awk 'NR>1 {printf "%-30s %6.2f MB\n", $$9, $$5/1048576}'

test:
	go test ./...

vet:
	go vet ./...

clean:
	rm -rf bin

FORCE:
