package functions

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/fngateway"
)

func TestApplyPassesAccessToTheGateway(t *testing.T) {
	e := setup(t)
	p := payload("r1")
	p.Access = &fngateway.Access{APIKeyHashes: []string{strings.Repeat("AB", 32)}, AllowCIDRs: []string{"203.0.113.7"}}
	if _, err := e.f.Apply(context.Background(), p, e.st); err != nil {
		t.Fatal(err)
	}
	a := e.gw.applied[0].Access
	if a.APIKeyHashes[0] != strings.Repeat("ab", 32) || a.AllowCIDRs[0] != "203.0.113.7/32" {
		t.Fatalf("access %+v", a)
	}

	p = payload("r2")
	p.Access = &fngateway.Access{AllowCIDRs: []string{"nope"}}
	var pe *commands.PayloadError
	if _, err := e.f.Apply(context.Background(), p, &bufStream{}); !errors.As(err, &pe) {
		t.Fatalf("invalid access accepted: %v", err)
	}
}
