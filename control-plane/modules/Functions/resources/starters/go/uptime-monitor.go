// Checks your URLs on a schedule (every 5 minutes) and alerts when one is down.
// Variables:
//
//	URLS               comma-separated URLs to check, e.g. https://example.com,https://api.example.com/health
//	ALERT_WEBHOOK_URL  a Slack or Discord webhook for alerts (optional: failures are also logged)
//
// GET /status runs the checks now and returns the results.
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"
)

const timeout = 10 * time.Second

type result struct {
	URL    string `json:"url"`
	OK     bool   `json:"ok"`
	Status int    `json:"status,omitempty"`
	MS     int64  `json:"ms"`
	Error  string `json:"error,omitempty"`
}

func check(ctx context.Context, client *http.Client, url string) result {
	started := time.Now()
	r := result{URL: url}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err == nil {
		var res *http.Response
		if res, err = client.Do(req); err == nil {
			res.Body.Close()
			r.Status, r.OK = res.StatusCode, res.StatusCode < 400
		}
	}
	if err != nil {
		r.Error = err.Error()
	}
	r.MS = time.Since(started).Milliseconds()
	return r
}

func checkAll(ctx context.Context) []result {
	var urls []string
	for _, u := range strings.Split(os.Getenv("URLS"), ",") {
		if u = strings.TrimSpace(u); u != "" {
			urls = append(urls, u)
		}
	}
	client := &http.Client{Transport: http.DefaultClient.Transport, Timeout: timeout}
	results := make([]result, len(urls))
	var wg sync.WaitGroup
	for i, u := range urls {
		wg.Add(1)
		go func() {
			defer wg.Done()
			results[i] = check(ctx, client, u)
		}()
	}
	wg.Wait()
	return results
}

func alert(ctx context.Context, down []result) {
	lines := []string{fmt.Sprintf("🚨 %d URL(s) down:", len(down))}
	for _, r := range down {
		why := r.Error
		if why == "" {
			why = fmt.Sprint(r.Status)
		}
		lines = append(lines, fmt.Sprintf("• %s: %s (%dms)", r.URL, why, r.MS))
	}
	text := strings.Join(lines, "\n")
	log.Println(text)
	hook := os.Getenv("ALERT_WEBHOOK_URL")
	if hook == "" {
		return
	}
	// Slack reads `text`, Discord reads `content`.
	body, _ := json.Marshal(map[string]string{"text": text, "content": text})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, hook, bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if res, err := (&http.Client{Transport: http.DefaultClient.Transport, Timeout: timeout}).Do(req); err != nil {
		log.Println("alert:", err)
	} else {
		res.Body.Close()
	}
}

func Scheduled(ctx context.Context, event Event) error {
	results := checkAll(ctx)
	if len(results) == 0 {
		log.Println("URLS is empty: nothing to check")
		return nil
	}
	var down []result
	for _, r := range results {
		if !r.OK {
			down = append(down, r)
		}
	}
	log.Printf("%d/%d up", len(results)-len(down), len(results))
	if len(down) > 0 {
		alert(ctx, down)
		// A failed run shows up in Observability → Scheduled tasks and opens an issue.
		names := make([]string, len(down))
		for i, r := range down {
			names[i] = r.URL
		}
		return fmt.Errorf("%d URL(s) down: %s", len(down), strings.Join(names, ", "))
	}
	return nil
}

var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /status", func(w http.ResponseWriter, r *http.Request) {
		results := checkAll(r.Context())
		up := true
		for _, res := range results {
			up = up && res.OK
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{"up": up, "results": results})
	})
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		io.WriteString(w, "Uptime monitor: runs every 5 minutes. GET /status checks now.\n")
	})
	return mux
}
