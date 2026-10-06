package functions

import (
	"slices"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

func TestApplyPayloadSecretsAreTheMaskedEnvValues(t *testing.T) {
	p, err := commands.Decode[ApplyPayload]([]byte(`{"site":"fn","release":"r1","image":"x","entrypoint":"index.js","files":[],"env":{"API_TOKEN":"tok-123456","REGION":"eu"},"mask":["API_TOKEN"]}`))
	if err != nil {
		t.Fatal(err)
	}
	if got := p.Secrets(); !slices.Equal(got, []string{"tok-123456"}) {
		t.Fatalf("secrets = %v", got)
	}
}
