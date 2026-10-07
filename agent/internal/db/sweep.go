package db

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// DrillMaxAge is how old a drill's leftovers must be before the sweep removes them: longer than any drill may run
// (the control plane's db.drill timeout is 2 h).
const DrillMaxAge = 3 * time.Hour

// SweepDrills removes what interrupted restore drills left behind (an agent restart or a crash mid-drill): containers
// labelled falak.db.drill, their scratch directories and their secret directories, older than maxAge. It returns how
// many it removed.
func (db *DB) SweepDrills(ctx context.Context, maxAge time.Duration) int {
	cutoff := time.Now().Add(-maxAge)
	removed := 0
	if cs, err := db.d.Docker.ContainerList(ctx, true, []string{LabelDrill}); err == nil {
		for _, c := range cs {
			if time.Unix(c.Created, 0).After(cutoff) {
				continue
			}
			if err := db.d.Docker.ContainerRemove(ctx, c.ID); err != nil {
				db.d.Logger.Warn("removing a leftover drill container", "id", c.ID, "err", err)
				continue
			}
			removed++
		}
	} else {
		db.d.Logger.Warn("listing drill containers", "err", err)
	}
	removed += removeOld(db.d.FS.P(db.d.DrillRoot), "", cutoff)
	removed += removeOld(db.d.FS.P(db.d.SecretsDir), "falak-db-drill-", cutoff)
	return removed
}

// removeOld removes the entries of dir (named with prefix) last modified before cutoff.
func removeOld(dir, prefix string, cutoff time.Time) int {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return 0
	}
	n := 0
	for _, e := range entries {
		if !strings.HasPrefix(e.Name(), prefix) {
			continue
		}
		info, err := e.Info()
		if err != nil || info.ModTime().After(cutoff) {
			continue
		}
		p := filepath.Join(dir, e.Name())
		_ = os.Chmod(p, 0o700) // secret directories are 0555
		if os.RemoveAll(p) == nil {
			n++
		}
	}
	return n
}
