package db

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
)

func TestSweepDrillsRemovesOldLeftovers(t *testing.T) {
	h := newHarness(t)
	ctx := context.Background()
	// A drill container left by a crash (the fake reports it as created long ago), and an instance that must stay.
	h.dock.ContainerCreate(ctx, "falak-db-drill-old", docker.CreateBody{Labels: map[string]string{LabelDrill: "old"}})
	h.dock.ContainerCreate(ctx, Container(instID), docker.CreateBody{Labels: map[string]string{LabelInstance: instID}})

	old := time.Now().Add(-4 * time.Hour)
	for _, p := range []string{"/var/lib/falak/drills/old", "/run/falak/secrets/falak-db-drill-old", "/var/lib/falak/drills/young", "/run/falak/secrets/falak-db-" + instID} {
		os.MkdirAll(h.path(p), 0o700)
	}
	for _, p := range []string{"/var/lib/falak/drills/old", "/run/falak/secrets/falak-db-drill-old", "/run/falak/secrets/falak-db-" + instID} {
		os.Chtimes(h.path(p), old, old)
	}
	os.Chmod(h.path("/run/falak/secrets/falak-db-drill-old"), 0o555)

	if n := h.db.SweepDrills(ctx, DrillMaxAge); n != 3 {
		t.Fatalf("removed %d", n)
	}
	if _, ok := h.dock.containers["falak-db-drill-old"]; ok {
		t.Error("the drill container stayed")
	}
	if _, ok := h.dock.containers[Container(instID)]; !ok {
		t.Error("an instance was removed")
	}
	for p, want := range map[string]bool{
		"/var/lib/falak/drills/old": false, "/run/falak/secrets/falak-db-drill-old": false,
		"/var/lib/falak/drills/young": true, "/run/falak/secrets/falak-db-" + instID: true,
	} {
		if _, err := os.Stat(h.path(p)); (err == nil) != want {
			t.Errorf("%s exists=%v, want %v", filepath.Base(p), err == nil, want)
		}
	}
}
