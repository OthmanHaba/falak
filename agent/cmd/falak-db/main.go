// Command falak-db is the helper inside every Falak database image (images/db): the container's entrypoint and
// healthcheck, and what the agent runs with `docker exec` for backups, restores and WAL/binlog spooling.
// See docs/DB_IMAGES.md for the CLI contract.
package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/dbhelper"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	h := &dbhelper.Helper{
		Engine: dbhelper.Engine(os.Getenv("FALAK_DB_ENGINE")),
		Env:    os.Getenv,
		Run:    dbhelper.ExecRunner{},
		Stdin:  os.Stdin,
		Stdout: os.Stdout,
		Stderr: os.Stderr,
		Exec:   syscall.Exec,
		Now:    time.Now,
		Sleep:  time.Sleep,
	}
	if os.Geteuid() == 0 {
		h.Chown = os.Lchown
	}
	code := dbhelper.Main(ctx, h, os.Args[1:])
	stop()
	os.Exit(code)
}
