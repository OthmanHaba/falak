package docker

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/kiln/agent/internal/commands"
)

func decode[P any](b []byte) error {
	_, err := commands.Decode[P](b)
	return err
}

// TestContractExamplesDecode ensures the schema example payloads decode strictly into executor types.
func TestContractExamplesDecode(t *testing.T) {
	for typ, fn := range map[string]func([]byte) error{
		"docker.pull": decode[PullPayload], "docker.run": decode[RunPayload], "docker.stop": decode[StopPayload], "docker.prune": decode[PrunePayload], "docker.compose.up": decode[ComposeUpPayload], "docker.compose.down": decode[ComposeDownPayload], "docker.compose.pull": decode[ComposePullPayload], "docker.compose.ps": decode[ComposePsPayload], "docker.compose.restart": decode[ComposeRestartPayload], "deploy.container.swap": decode[SwapPayload],
	} {
		b, err := os.ReadFile(filepath.Join("..", "..", "testdata", "command-examples", typ+".json"))
		if err != nil {
			t.Fatal(err)
		}
		if err := fn(b); err != nil {
			t.Errorf("%s: %v", typ, err)
		}
	}
}
