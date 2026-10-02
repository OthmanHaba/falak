package system

import (
	"bytes"
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

const (
	ondrejNoRelease = "E: The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute Release' does not have a Release file.\nN: Updating from such a repository can't be done securely, and is therefore disabled by default.\n"
	dockerNoRelease = "E: The repository 'https://download.docker.com/linux/ubuntu resolute Release' does not have a Release file.\n"
)

func aptSources(t *testing.T, files map[string]string) hostfs.FS {
	t.Helper()
	root := t.TempDir()
	for p, c := range files {
		full := filepath.Join(root, p)
		os.MkdirAll(filepath.Dir(full), 0o755)
		if err := os.WriteFile(full, []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return hostfs.FS{Root: root}
}

func TestAptUpdateDisablesAnOndrejSourceWithoutARelease(t *testing.T) {
	fs := aptSources(t, map[string]string{
		"/etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources": "Types: deb\nURIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\nSuites: resolute\nComponents: main\n",
		"/etc/apt/sources.list.d/ubuntu.sources":                     "Types: deb\nURIs: http://archive.ubuntu.com/ubuntu/\nSuites: resolute\n",
	})
	runs := 0
	f := (&runnertest.Fake{}).OnFunc("apt-get update", func(runnertest.Call) (runner.Result, error) {
		runs++
		if fs.Exists("/etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources") {
			return runner.Result{ExitCode: 100, Stderr: []byte(ondrejNoRelease)}, nil
		}
		return runner.Result{}, nil
	})
	var stderr bytes.Buffer
	a := Apt{R: f, FS: fs, Stderr: &stderr}
	if err := a.Update(context.Background()); err != nil {
		t.Fatal(err)
	}
	if runs != 2 {
		t.Fatalf("apt-get update ran %d times, want 2", runs)
	}
	if !fs.Exists("/etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources.disabled-by-kiln") || !fs.Exists("/etc/apt/sources.list.d/ubuntu.sources") {
		t.Fatal("ondrej source not disabled (or another one touched)")
	}
	if !strings.Contains(stderr.String(), "warning: https://ppa.launchpadcontent.net/ondrej/php/ubuntu has no release for this distribution; disabled /etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources") {
		t.Fatalf("warning %q", stderr.String())
	}
}

func TestAptUpdateNamesTheBrokenSourceFile(t *testing.T) {
	fs := aptSources(t, map[string]string{
		"/etc/apt/sources.list.d/docker.list": "deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu resolute stable\n",
	})
	runs := 0
	f := (&runnertest.Fake{}).OnFunc("apt-get update", func(runnertest.Call) (runner.Result, error) {
		runs++
		return runner.Result{ExitCode: 100, Stderr: []byte(dockerNoRelease)}, nil
	})
	err := Apt{R: f, FS: fs}.Update(context.Background())
	var re *RepoError
	if !errors.As(err, &re) || runs != 1 {
		t.Fatalf("%v (%d runs)", err, runs)
	}
	want := "apt-get update failed: repository https://download.docker.com/linux/ubuntu (/etc/apt/sources.list.d/docker.list) breaks apt-get update: The repository 'https://download.docker.com/linux/ubuntu resolute Release' does not have a Release file.; fix or remove that source file and retry"
	if err.Error() != want {
		t.Fatalf("got  %s\nwant %s", err, want)
	}
	var ee *runner.ExitError
	if !errors.As(err, &ee) || ee.Code != 100 {
		t.Fatal("the apt-get error must stay reachable")
	}
	if !fs.Exists("/etc/apt/sources.list.d/docker.list") {
		t.Fatal("only ondrej sources are disabled")
	}
}

func TestAptUpdateOndrejInSourcesListIsReportedNotRenamed(t *testing.T) {
	fs := aptSources(t, map[string]string{"/etc/apt/sources.list": "deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute main\n"})
	f := (&runnertest.Fake{}).On("apt-get update", runner.Result{ExitCode: 100, Stderr: []byte(ondrejNoRelease)})
	err := Apt{R: f, FS: fs}.Update(context.Background())
	if err == nil || !strings.Contains(err.Error(), "(/etc/apt/sources.list)") || !fs.Exists("/etc/apt/sources.list") {
		t.Fatalf("%v", err)
	}
}

func TestAptUpdateOtherFailuresPassThrough(t *testing.T) {
	f := (&runnertest.Fake{}).On("apt-get update", runner.Result{ExitCode: 100, Stderr: []byte("E: Could not get lock /var/lib/apt/lists/lock\n")})
	err := Apt{R: f, FS: aptSources(t, nil)}.Update(context.Background())
	var re *RepoError
	if err == nil || errors.As(err, &re) || !strings.Contains(err.Error(), "Could not get lock") {
		t.Fatalf("%v", err)
	}
}

func TestAptCandidate(t *testing.T) {
	f := (&runnertest.Fake{}).
		On("apt-cache policy php8.5-cli", runner.Result{Stdout: []byte("php8.5-cli:\n  Installed: (none)\n  Candidate: 8.5.0-1ubuntu1\n")}).
		On("apt-cache policy php8.4-cli", runner.Result{Stdout: []byte("php8.4-cli:\n  Installed: (none)\n  Candidate: (none)\n")})
	a := Apt{R: f}
	if v, _ := a.Candidate(context.Background(), "php8.5-cli"); v != "8.5.0-1ubuntu1" {
		t.Fatal(v)
	}
	for _, p := range []string{"php8.4-cli", "php7.0-cli"} {
		if v, _ := a.Candidate(context.Background(), p); v != "" {
			t.Fatalf("%s: %q", p, v)
		}
	}
}
