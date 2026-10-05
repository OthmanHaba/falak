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

// A stack's bootstrap pass starts only the services its split-out sites use (compose pulls in their depends_on),
// without removing the others as orphans.
func TestComposeUpStartsOnlyTheGivenServices(t *testing.T) {
	s, _, fr, _, _ := newSvc(t)
	no := false
	fin, _ := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/falak/compose/shop",
		Files: []ComposeFile{{Name: "compose.yaml", Content: "services: {}\n"}}, RemoveOrphans: &no, Wait: true, Services: []string{"postgres", "redis"}})
	if fin.Error != "" {
		t.Fatalf("%+v", fin)
	}
	if got := fr.Calls()[0].Line; got != "docker compose -p shop -f compose.yaml up -d --pull missing --wait -- postgres redis" {
		t.Fatalf("command %q", got)
	}

	fin, _ = exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/falak/compose/shop", Services: []string{"--rm"}})
	if fin.ExitCode == nil || *fin.ExitCode != 2 {
		t.Fatalf("an option as a service was accepted: %+v", fin)
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
