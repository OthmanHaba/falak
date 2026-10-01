package main

import (
	"encoding/json"
	"net/http"
	"time"
)

// Handler serves the function's HTTP requests: an http.Handler (here a ServeMux, whose patterns name the routes
// in Observability) or a func(http.ResponseWriter, *http.Request). Kiln adds main(): don't declare one.
var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"message": "Hello from Kiln!", "time": time.Now().UTC().Format(time.RFC3339)})
	})
	mux.HandleFunc("GET /hello/{name}", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"message": "Hello, " + r.PathValue("name") + "!"})
	})
	return mux
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
