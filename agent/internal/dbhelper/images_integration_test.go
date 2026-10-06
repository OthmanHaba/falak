//go:build dbimages

package dbhelper

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// Opt-in, needs Docker: go test -tags dbimages ./internal/dbhelper -run TestImages -v -timeout 60m
//
// Builds each image and runs images/db/test.sh against real containers. FALAK_DB_IMAGES picks the images
// ("postgres:17,mysql:8.4"); the default is one per engine family.
func TestImages(t *testing.T) {
	list := os.Getenv("FALAK_DB_IMAGES")
	if list == "" {
		list = "postgres:17,mysql:8.4,mariadb:11.4,redis:8,valkey:8.1"
	}
	script, err := filepath.Abs("../../../images/db/test.sh")
	if err != nil {
		t.Fatal(err)
	}
	for _, item := range strings.Split(list, ",") {
		engine, version, ok := strings.Cut(strings.TrimSpace(item), ":")
		if !ok {
			t.Fatalf("FALAK_DB_IMAGES: %q is not engine:version", item)
		}
		t.Run(engine+"-"+version, func(t *testing.T) {
			cmd := exec.Command(script, engine, version)
			out, err := cmd.CombinedOutput()
			if err != nil {
				t.Fatalf("%v\n%s", err, out)
			}
			t.Logf("%s", out[max(0, len(out)-2000):])
		})
	}
}
