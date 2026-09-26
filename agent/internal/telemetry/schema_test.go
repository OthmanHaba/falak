package telemetry

import (
	"encoding/json"
	"os"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v6"
)

func TestExampleMatchesSchema(t *testing.T) {
	const schemaPath = "../../../contracts/agent-protocol/commands/telemetry.configure.schema.json"
	c := jsonschema.NewCompiler()
	c.AssertFormat()
	sch, err := c.Compile(schemaPath)
	if err != nil {
		t.Fatal(err)
	}
	b, err := os.ReadFile("../../testdata/command-examples/telemetry.configure.json")
	if err != nil {
		t.Fatal(err)
	}
	var v any
	if err := json.Unmarshal(b, &v); err != nil {
		t.Fatal(err)
	}
	if err := sch.Validate(v); err != nil {
		t.Fatalf("example does not validate: %v", err)
	}
}
