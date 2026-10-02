package docker

import (
	"strings"
	"testing"
)

// A service split out of a stack at the stack's creation deploys first: the stack's network is created with Compose's
// labels (so the stack's first `docker compose up` adopts it) instead of being waited for.
func TestContainerSwapCreatesAComposeProjectsNetwork(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	ok := 200
	p := swapPayload(healthServer(t, &ok), healthServer(t, &ok))
	p.Networks = []NetworkJoin{{Name: "shop_default", Aliases: []string{"api"}, Compose: &ComposeNetwork{Project: "shop", Network: "default"}}}

	fin, col := exec1(t, s, "deploy.container.swap", p)
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	if got := e.networkLabels["shop_default"]; got["com.docker.compose.project"] != "shop" || got["com.docker.compose.network"] != "default" {
		t.Fatalf("labels %v", got)
	}
	if got := e.networks["shop_default"]; len(got) != 1 || !strings.HasSuffix(got[0], ":api") {
		t.Fatalf("joins %v", got)
	}
	if !strings.Contains(col.Output(""), "created network shop_default") {
		t.Fatalf("output %q", col.Output(""))
	}
}

// A network that already exists (the stack is running) is joined as it is; nothing is created.
func TestContainerSwapJoinsAnExistingComposeNetworkWithoutCreatingIt(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	ok := 200
	p := swapPayload(healthServer(t, &ok), healthServer(t, &ok))
	e.networks["shop_default"] = nil
	p.Networks = []NetworkJoin{{Name: "shop_default", Aliases: []string{"api"}, Compose: &ComposeNetwork{Project: "shop", Network: "default"}}}

	fin, col := exec1(t, s, "deploy.container.swap", p)
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	if _, created := e.networkLabels["shop_default"]; created {
		t.Fatalf("an existing network was re-created")
	}
}
