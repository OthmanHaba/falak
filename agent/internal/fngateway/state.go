package fngateway

import (
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
)

// stateFile is gateway.json: the registered functions (containers are re-discovered by label).
type stateFile struct {
	Functions map[string]persisted `json:"functions"`
}

type persisted struct {
	Spec     Spec `json:"spec"`
	NextSlot int  `json:"next_slot"`
}

func readState(path string) (stateFile, error) {
	st := stateFile{Functions: map[string]persisted{}}
	if path == "" {
		return st, nil
	}
	b, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return st, nil
	}
	if err != nil {
		return st, err
	}
	if err := json.Unmarshal(b, &st); err != nil {
		return st, err
	}
	if st.Functions == nil {
		st.Functions = map[string]persisted{}
	}
	return st, nil
}

// persistLocked writes gateway.json (atomically, 0600: specs carry the functions' environment). Call with g.mu
// held.
func (g *Gateway) persistLocked() {
	if g.o.StateFile == "" {
		return
	}
	st := stateFile{Functions: map[string]persisted{}}
	for site, f := range g.fns {
		st.Functions[site] = persisted{Spec: f.spec, NextSlot: f.nextSlot}
	}
	b, _ := json.MarshalIndent(st, "", "  ")
	if err := os.MkdirAll(filepath.Dir(g.o.StateFile), 0o755); err != nil {
		g.o.Logger.Error("persist gateway state", "err", err)
		return
	}
	tmp := g.o.StateFile + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		g.o.Logger.Error("persist gateway state", "err", err)
		return
	}
	if err := os.Rename(tmp, g.o.StateFile); err != nil {
		g.o.Logger.Error("persist gateway state", "err", err)
	}
}
