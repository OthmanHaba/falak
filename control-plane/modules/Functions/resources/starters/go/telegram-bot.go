// A Telegram bot over webhooks. Create a bot with @BotFather, then set in Variables:
//
//	TELEGRAM_BOT_TOKEN       the token from BotFather
//	TELEGRAM_WEBHOOK_SECRET  any random string (Telegram sends it back on every update)
//
// Then open https://<this function>/setup?secret=<TELEGRAM_WEBHOOK_SECRET> once to register the webhook.
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

var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /telegram", update)
	mux.HandleFunc("GET /setup", setup)
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		io.WriteString(w, "Telegram bot: open /setup?secret=… once to register the webhook.\n")
	})
	return mux
}

// telegram calls the Bot API (with the request's context, so the call shows up in Observability).
func telegram(ctx context.Context, method string, payload any) (map[string]any, error) {
	body, _ := json.Marshal(payload)
	url := "https://api.telegram.org/bot" + os.Getenv("TELEGRAM_BOT_TOKEN") + "/" + method
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	client := &http.Client{Timeout: 10 * time.Second}
	res, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	var out map[string]any
	_ = json.NewDecoder(res.Body).Decode(&out)
	if res.StatusCode >= 400 {
		log.Printf("telegram %s failed: %d %v", method, res.StatusCode, out)
	}
	return out, nil
}

func reply(text string) string {
	switch text {
	case "/start":
		return "Hi! I run on a Kiln function. Send me anything and I will echo it."
	case "/help":
		return "Commands: /start, /help, /time. Anything else is echoed back."
	case "/time":
		return "It is " + time.Now().UTC().Format("Mon, 02 Jan 2006 15:04:05") + " UTC."
	}
	return "You said: " + text
}

func secretMatches(got string) bool {
	// Without a secret every caller would match: refuse instead.
	secret := os.Getenv("TELEGRAM_WEBHOOK_SECRET")
	return secret != "" && subtle.ConstantTimeCompare([]byte(got), []byte(secret)) == 1
}

func update(w http.ResponseWriter, r *http.Request) {
	if !secretMatches(r.Header.Get("X-Telegram-Bot-Api-Secret-Token")) {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	var u struct {
		Message struct {
			Text string `json:"text"`
			Chat struct {
				ID int64 `json:"id"`
			} `json:"chat"`
		} `json:"message"`
	}
	if err := json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&u); err != nil {
		http.Error(w, "invalid JSON", http.StatusBadRequest)
		return
	}
	if text := strings.TrimSpace(u.Message.Text); text != "" {
		if _, err := telegram(r.Context(), "sendMessage", map[string]any{"chat_id": u.Message.Chat.ID, "text": reply(text)}); err != nil {
			log.Println("sendMessage:", err)
		}
	}
	w.Header().Set("Content-Type", "application/json")
	io.WriteString(w, `{"ok":true}`)
}

func setup(w http.ResponseWriter, r *http.Request) {
	if os.Getenv("TELEGRAM_BOT_TOKEN") == "" || os.Getenv("TELEGRAM_WEBHOOK_SECRET") == "" {
		http.Error(w, "set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET", http.StatusInternalServerError)
		return
	}
	if !secretMatches(r.URL.Query().Get("secret")) {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	proto := r.Header.Get("X-Forwarded-Proto")
	if proto == "" {
		proto = "https"
	}
	url := fmt.Sprintf("%s://%s/telegram", proto, r.Host)
	res, err := telegram(r.Context(), "setWebhook", map[string]any{"url": url, "secret_token": os.Getenv("TELEGRAM_WEBHOOK_SECRET"), "allowed_updates": []string{"message"}})
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]any{"webhook": url, "telegram": res})
}
