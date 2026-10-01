package main

import (
	"encoding/json"
	"net/url"
	"os"
	"testing"
)

// The function's main() is generated at install time; tests need one to build the package.
func main() {}

func TestRedaction(t *testing.T) {
	b, err := os.ReadFile("../../tests/redact-cases.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases struct{ Paths, URLs, Texts [][2]string }
	if err := json.Unmarshal(b, &cases); err != nil {
		t.Fatal(err)
	}
	for _, c := range cases.Paths {
		if got := kilnRedactPath(c[0]); got != c[1] {
			t.Errorf("kilnRedactPath(%q) = %q, want %q", c[0], got, c[1])
		}
	}
	for _, c := range cases.Texts {
		if got := kilnRedactText(c[0]); got != c[1] {
			t.Errorf("kilnRedactText(%q) = %q, want %q", c[0], got, c[1])
		}
	}
	for _, c := range cases.URLs {
		u, err := url.Parse(c[0])
		if err != nil {
			t.Fatal(err)
		}
		if got := kilnSafeURL(u); got != c[1] {
			t.Errorf("kilnSafeURL(%q) = %q, want %q", c[0], got, c[1])
		}
	}
	if len(cases.Paths) == 0 || len(cases.URLs) == 0 {
		t.Fatal("no cases")
	}
}
