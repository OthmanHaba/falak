// A notification relay: POST {"title", "text", "level"} and it posts to Slack and/or Discord.
// Variables:
//
//	NOTIFY_TOKEN         callers send Authorization: Bearer <NOTIFY_TOKEN>
//	SLACK_WEBHOOK_URL    a Slack incoming webhook (optional)
//	DISCORD_WEBHOOK_URL  a Discord channel webhook (optional)
package main

import (
	"bytes"
	"context"
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strings"
	"time"
)

var icons = map[string]string{"info": "ℹ️", "success": "✅", "warning": "⚠️", "error": "🚨"}

var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /notify", notify)
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		io.WriteString(w, "POST /notify with Authorization: Bearer <NOTIFY_TOKEN>\n")
	})
	return mux
}

// post sends JSON with the request's context (the call shows up in Observability).
func post(ctx context.Context, url string, payload any) error {
	body, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	res, err := (&http.Client{Timeout: 10 * time.Second}).Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode >= 400 {
		b, _ := io.ReadAll(io.LimitReader(res.Body, 2048))
		return fmt.Errorf("%d %s", res.StatusCode, b)
	}
	return nil
}

func notify(w http.ResponseWriter, r *http.Request) {
	token := os.Getenv("NOTIFY_TOKEN")
	if token == "" || subtle.ConstantTimeCompare([]byte(r.Header.Get("Authorization")), []byte("Bearer "+token)) != 1 {
		http.Error(w, "unauthorized", http.StatusUnauthorized)
		return
	}
	var n struct {
		Text  string `json:"text"`
		Title string `json:"title"`
		Level string `json:"level"`
	}
	if err := json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&n); err != nil || n.Text == "" {
		http.Error(w, `send {"text": "…", "title"?, "level"?}`, http.StatusUnprocessableEntity)
		return
	}
	icon, ok := icons[n.Level]
	if !ok {
		icon = icons["info"]
	}
	line := icon + " " + n.Text
	if n.Title != "" {
		line = icon + " *" + n.Title + "*\n" + n.Text
	}

	sent := []string{}
	if url := os.Getenv("SLACK_WEBHOOK_URL"); url != "" {
		if err := post(r.Context(), url, map[string]string{"text": line}); err != nil {
			log.Println("slack:", err)
		} else {
			sent = append(sent, "slack")
		}
	}
	if url := os.Getenv("DISCORD_WEBHOOK_URL"); url != "" {
		if err := post(r.Context(), url, map[string]string{"content": strings.ReplaceAll(line, "*", "**")}); err != nil {
			log.Println("discord:", err)
		} else {
			sent = append(sent, "discord")
		}
	}
	if len(sent) == 0 {
		http.Error(w, "set SLACK_WEBHOOK_URL or DISCORD_WEBHOOK_URL", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]any{"sent": sent})
}
