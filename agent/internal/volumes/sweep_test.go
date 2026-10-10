package volumes

import (
	"os"
	"testing"
	"time"
)

func TestSweepDrillsRemovesOldScratchDirectories(t *testing.T) {
	e := newEnv(t, nil)
	old := time.Now().Add(-4 * time.Hour)
	for _, name := range []string{"old", "young"} {
		os.MkdirAll(e.fs.P("/var/lib/falak/volumes/.drills/"+name+"/x"), 0o700)
	}
	os.Chtimes(e.fs.P("/var/lib/falak/volumes/.drills/old"), old, old)
	if n := e.svc.SweepDrills(3 * time.Hour); n != 1 {
		t.Fatalf("removed %d", n)
	}
	if _, err := os.Stat(e.fs.P("/var/lib/falak/volumes/.drills/young")); err != nil {
		t.Fatal("a recent drill's directory was removed")
	}
}
