// Runs on its schedules (Schedules tab) in a one-shot container, and serves HTTP like any function.
// The event says which schedule fired: Name, Schedule, Cron, Trigger ("cron" or "manual") and ScheduledTime.
package main

import (
	"context"
	"io"
	"log"
	"net/http"
	"time"
)

// Scheduled runs once per schedule run; returning an error (or panicking) marks the run failed.
func Scheduled(ctx context.Context, event Event) error {
	log.Printf("%s (%s) started at %s", event.Name, event.Trigger, event.ScheduledTime.UTC().Format(time.RFC3339))

	// Do the work here: clean up rows, send a report, sync an API… Calls made with ctx show up in Observability.
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, "https://api.github.com/zen", nil)
	if err != nil {
		return err
	}
	res, err := (&http.Client{Transport: http.DefaultClient.Transport, Timeout: 10 * time.Second}).Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	zen, _ := io.ReadAll(io.LimitReader(res.Body, 1024))
	log.Printf("zen: %s", zen)
	return nil
}

func Handler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	io.WriteString(w, `{"ok":true,"hint":"This function also runs on a schedule: see its Schedules tab."}`)
}
