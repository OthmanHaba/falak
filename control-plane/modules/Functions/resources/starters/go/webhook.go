// Receives webhooks signed with HMAC-SHA256 (GitHub style: X-Hub-Signature-256: sha256=<hex>).
// Set WEBHOOK_SECRET in Variables and the same secret in the sender.
package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"os"
)

var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /webhook", webhook)
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		io.WriteString(w, "POST signed webhooks to /webhook\n")
	})
	return mux
}

func webhook(w http.ResponseWriter, r *http.Request) {
	// Without a secret anyone could sign with an empty key: refuse instead.
	secret := os.Getenv("WEBHOOK_SECRET")
	if secret == "" {
		http.Error(w, "WEBHOOK_SECRET is not set", http.StatusInternalServerError)
		return
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, 5<<20))
	if err != nil {
		http.Error(w, "could not read the body", http.StatusBadRequest)
		return
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write(body)
	expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))
	if !hmac.Equal([]byte(expected), []byte(r.Header.Get("X-Hub-Signature-256"))) {
		http.Error(w, "invalid signature", http.StatusUnauthorized)
		return
	}

	var payload map[string]any
	if err := json.Unmarshal(body, &payload); err != nil {
		http.Error(w, "invalid JSON", http.StatusBadRequest)
		return
	}
	event := r.Header.Get("X-GitHub-Event")
	if event == "" {
		event = "unknown"
	}
	log.Println("received", event, payload)

	w.Header().Set("Content-Type", "application/json")
	io.WriteString(w, `{"ok":true}`)
}
