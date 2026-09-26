# Kiln local end-to-end simulation.
#   make up [METRICS=mimir] [SIM_AGENT_ARCH=amd64]
#   make e2e
#   make enroll TOKEN=<install-token> [SERVERS="srv-app-1 srv-db-1"]
#   make logs [S=control-plane]
#   make down | make reset

METRICS ?= victoriametrics
COMPOSE := docker compose -p kiln-sim --env-file sim.env --env-file .data/secrets.env
ALL_PROFILES := --profile victoriametrics --profile mimir
export SIM_METRICS_PROFILE := $(METRICS)
ifdef SIM_AGENT_ARCH
export SIM_AGENT_ARCH
endif

.PHONY: up down logs reset enroll e2e ps build shell ca token enroll-all

# Sim-only secrets, generated once per sim (removed by `make reset`).
.data/secrets.env:
	@mkdir -p .data
	@{ echo "APP_KEY=base64:$$(openssl rand -base64 32)"; \
	   echo "REVERB_APP_ID=kiln-sim"; \
	   echo "REVERB_APP_KEY=$$(openssl rand -hex 10)"; \
	   echo "REVERB_APP_SECRET=$$(openssl rand -hex 10)"; \
	   echo "KILN_LOCAL_BUILDER_TOKEN=kbt_$$(openssl rand -hex 24)"; } > $@
	@chmod 600 $@

build: .data/secrets.env
	$(COMPOSE) --profile $(METRICS) build

up: .data/secrets.env
	$(COMPOSE) --profile $(METRICS) up -d --build --wait --wait-timeout 420
	@$(COMPOSE) --profile $(METRICS) ps --format 'table {{.Service}}\t{{.Status}}'
	@echo
	@echo "control plane: https://localhost:$$(grep ^SIM_EDGE_HTTPS_PORT sim.env | cut -d= -f2) (edge CA: make ca -> .data/edge-root.crt)"
	@echo "grafana:       http://localhost:$$(grep ^KILN_GRAFANA_PORT sim.env | cut -d= -f2) (admin/admin)"

ps:
	$(COMPOSE) $(ALL_PROFILES) ps

down:
	$(COMPOSE) $(ALL_PROFILES) down --remove-orphans

reset:
	$(COMPOSE) $(ALL_PROFILES) down -v --remove-orphans
	rm -rf .data

logs:
	$(COMPOSE) $(ALL_PROFILES) logs -f --tail=200 $(S)

ca:
	@mkdir -p .data
	$(COMPOSE) cp edge:/kiln/edge-pki/root.crt .data/edge-root.crt
	@echo "curl --cacert sim/.data/edge-root.crt https://localhost:$$(grep ^SIM_EDGE_HTTPS_PORT sim.env | cut -d= -f2)/up"

shell:
	$(COMPOSE) exec $(or $(S),srv-app-1) bash

enroll:
	@test -n "$(TOKEN)" || { echo "usage: make enroll TOKEN=<install-token> [SERVERS=...]"; exit 2; }
	./enroll.sh "$(TOKEN)" $(SERVERS)

# Dev shortcut until the Servers UI exists: mint a token via Fleet's Enrollment contract.
token:
	@./mint-token.sh

# Mint one token per server and enroll all three.
enroll-all:
	@for s in srv-app-1 srv-app-2 srv-db-1; do ./enroll.sh "$$(./mint-token.sh)" $$s || exit 1; done

e2e:
	./e2e.sh
