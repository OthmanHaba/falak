# falak-agent (servers), falak (CLI), falak-builder (build worker). Zero CGO, static, stripped.
VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -buildid= -X github.com/OthmanHaba/falak/agent/internal/version.Version=$(VERSION)
GOFLAGS := -trimpath

AGENT_PLATFORMS   := linux-amd64 linux-arm64
CLI_PLATFORMS     := darwin-amd64 darwin-arm64 linux-amd64 linux-arm64
BUILDER_PLATFORMS := linux-amd64 linux-arm64

BINARIES := $(foreach p,$(AGENT_PLATFORMS),bin/falak-agent-$(p)) \
            $(foreach p,$(CLI_PLATFORMS),bin/falak-$(p)) \
            $(foreach p,$(BUILDER_PLATFORMS),bin/falak-builder-$(p))

.PHONY: build agent cli builder test vet clean sizes

build: $(BINARIES)
	@$(MAKE) --no-print-directory sizes

agent: $(foreach p,$(AGENT_PLATFORMS),bin/falak-agent-$(p))
cli: $(foreach p,$(CLI_PLATFORMS),bin/falak-$(p))
builder: $(foreach p,$(BUILDER_PLATFORMS),bin/falak-builder-$(p))

# $(call gobuild,<cmd dir>,<os-arch>)
define gobuild
	@mkdir -p bin
	CGO_ENABLED=0 GOOS=$(word 1,$(subst -, ,$(2))) GOARCH=$(word 2,$(subst -, ,$(2))) \
		go build $(GOFLAGS) -ldflags '$(LDFLAGS)' -o $@ ./cmd/$(1)
endef

# Static pattern rules: falak-builder-* and falak-agent-* must not fall into the falak-% CLI rule.
# <binary>.version: the version the control plane announces when it serves this build (agent upgrades).
$(foreach p,$(AGENT_PLATFORMS),bin/falak-agent-$(p)): bin/falak-agent-%: FORCE
	$(call gobuild,falak-agent,$*)
	@echo '$(VERSION)' > $@.version

$(foreach p,$(BUILDER_PLATFORMS),bin/falak-builder-$(p)): bin/falak-builder-%: FORCE
	$(call gobuild,falak-builder,$*)

$(foreach p,$(CLI_PLATFORMS),bin/falak-$(p)): bin/falak-%: FORCE
	$(call gobuild,falak,$*)

sizes:
	@ls -l bin/ | awk 'NR>1 && $$9 !~ /\.version$$/ {printf "%-30s %6.2f MB\n", $$9, $$5/1048576}'

test:
	go test ./...

vet:
	go vet ./...

clean:
	rm -rf bin

FORCE:
