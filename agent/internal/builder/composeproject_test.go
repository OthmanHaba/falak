package builder

import (
	"encoding/json"
	"io/fs"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

// The control plane's preview applies the same rules (contracts/compose/merge-cases.json).
func TestLoadComposeProjectSharedCases(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("..", "..", "..", "contracts", "compose", "merge-cases.json"))
	if err != nil {
		t.Fatal(err)
	}
	var spec struct {
		Cases []struct {
			Name       string            `json:"name"`
			Repo       map[string]string `json:"repo"`
			Files      []string          `json:"files"`
			Profiles   []string          `json:"profiles"`
			Expected   map[string]any    `json:"expected"`
			References []string          `json:"references"`
			Error      string            `json:"error"`
		} `json:"cases"`
	}
	if err := json.Unmarshal(raw, &spec); err != nil {
		t.Fatal(err)
	}
	for _, c := range spec.Cases {
		t.Run(c.Name, func(t *testing.T) {
			read := func(rel string) ([]byte, error) {
				if s, ok := c.Repo[rel]; ok {
					return []byte(s), nil
				}
				return nil, fs.ErrNotExist
			}
			doc, _, err := LoadComposeProject(read, c.Files, c.Profiles)
			if c.Error != "" {
				if err == nil || !strings.Contains(err.Error(), c.Error) {
					t.Fatalf("want error %q, got %v", c.Error, err)
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if got, want := normalizeJSON(t, doc), normalizeJSON(t, c.Expected); !reflect.DeepEqual(got, want) {
				g, _ := json.MarshalIndent(got, "", "  ")
				w, _ := json.MarshalIndent(want, "", "  ")
				t.Fatalf("merged:\n%s\nwant:\n%s", g, w)
			}
			refs := ComposeReferences(doc)
			if len(refs) == 0 && len(c.References) == 0 {
				return
			}
			if !reflect.DeepEqual(refs, c.References) {
				t.Fatalf("references %v, want %v", refs, c.References)
			}
		})
	}
}

func TestValidAssetPath(t *testing.T) {
	for p, want := range map[string]bool{
		"logo@2x.png": true, "my file.txt": true, "a/b+c/d.conf": true, "ü/ñ.txt": true,
		"": false, "/abs": false, "../x": false, "a/../b": false, "a//b": false, "a/./b": false, ".": false,
		"a\\b": false, "a\x01b": false, "a\x7fb": false,
	} {
		if got := ValidAssetPath(p); got != want {
			t.Errorf("ValidAssetPath(%q) = %v, want %v", p, got, want)
		}
	}
}

// normalizeJSON round-trips through JSON so YAML ints and JSON numbers compare equal.
func normalizeJSON(t *testing.T, v any) any {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	var out any
	if err := json.Unmarshal(b, &out); err != nil {
		t.Fatal(err)
	}
	return out
}
