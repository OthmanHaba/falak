// A caching proxy in front of another API: hides its key, adds CORS and caches GET responses.
// Variables:
//
//	UPSTREAM_URL            e.g. https://api.example.com
//	UPSTREAM_AUTHORIZATION  sent upstream as the Authorization header (optional, never exposed to browsers)
//	CACHE_SECONDS           how long GET responses are cached in memory (default 60)
//	ALLOWED_ORIGIN          CORS origin (default *)
//
// The cache lives in the instance's memory: it is per instance and empty after the function scales to zero.
package main

import (
	"io"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
)

type entry struct {
	expires     time.Time
	status      int
	body        []byte
	contentType string
}

var (
	cacheMu sync.Mutex
	cache   = map[string]entry{}
	client  = &http.Client{Timeout: 30 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
)

func Handler(w http.ResponseWriter, r *http.Request) {
	origin := os.Getenv("ALLOWED_ORIGIN")
	if origin == "" {
		origin = "*"
	}
	w.Header().Set("Access-Control-Allow-Origin", origin)
	if r.Method == http.MethodOptions {
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE")
		w.Header().Set("Access-Control-Allow-Headers", "*")
		w.WriteHeader(http.StatusNoContent)
		return
	}

	upstream, err := url.Parse(strings.TrimRight(os.Getenv("UPSTREAM_URL"), "/"))
	if err != nil || upstream.Host == "" {
		http.Error(w, "UPSTREAM_URL is not set", http.StatusInternalServerError)
		return
	}
	// Only ever the upstream's host: the path is appended, never parsed as a URL of its own.
	target := *upstream
	target.Path = upstream.Path + "/" + strings.TrimLeft(r.URL.Path, "/")
	target.RawQuery = r.URL.RawQuery
	key := target.String()

	if r.Method == http.MethodGet {
		cacheMu.Lock()
		hit, ok := cache[key]
		cacheMu.Unlock()
		if ok && hit.expires.After(time.Now()) {
			w.Header().Set("Content-Type", hit.contentType)
			w.Header().Set("X-Cache", "HIT")
			w.WriteHeader(hit.status)
			w.Write(hit.body)
			return
		}
	}

	req, err := http.NewRequestWithContext(r.Context(), r.Method, key, r.Body)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	req.Header.Set("Accept", r.Header.Get("Accept"))
	if ct := r.Header.Get("Content-Type"); ct != "" {
		req.Header.Set("Content-Type", ct)
	}
	if auth := os.Getenv("UPSTREAM_AUTHORIZATION"); auth != "" {
		req.Header.Set("Authorization", auth)
	}
	res, err := client.Do(req)
	if err != nil {
		http.Error(w, "upstream: "+err.Error(), http.StatusBadGateway)
		return
	}
	defer res.Body.Close()
	body, err := io.ReadAll(io.LimitReader(res.Body, 10<<20))
	if err != nil {
		http.Error(w, "upstream: "+err.Error(), http.StatusBadGateway)
		return
	}
	contentType := res.Header.Get("Content-Type")
	if contentType == "" {
		contentType = "application/octet-stream"
	}

	if r.Method == http.MethodGet && res.StatusCode < 300 {
		seconds, err := strconv.Atoi(os.Getenv("CACHE_SECONDS"))
		if err != nil {
			seconds = 60
		}
		cacheMu.Lock()
		if len(cache) >= 1000 {
			for k := range cache { // drop one entry (any)
				delete(cache, k)
				break
			}
		}
		cache[key] = entry{time.Now().Add(time.Duration(seconds) * time.Second), res.StatusCode, body, contentType}
		cacheMu.Unlock()
	}
	w.Header().Set("Content-Type", contentType)
	w.Header().Set("X-Cache", "MISS")
	w.WriteHeader(res.StatusCode)
	w.Write(body)
}
