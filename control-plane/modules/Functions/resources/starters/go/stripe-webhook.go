// Stripe webhook endpoint: verifies the Stripe-Signature header (no Stripe SDK needed) and handles events.
// In Stripe: Developers → Webhooks → Add endpoint → https://<this function>/stripe, then copy the signing secret
// (whsec_…) into STRIPE_WEBHOOK_SECRET in Variables.
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
	"strconv"
	"strings"
	"time"
)

const toleranceSeconds = 300

var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /stripe", stripe)
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		io.WriteString(w, "Stripe webhooks: POST /stripe\n")
	})
	return mux
}

func verify(secret string, body []byte, header string) bool {
	var timestamp string
	var signatures []string
	for _, part := range strings.Split(header, ",") {
		k, v, ok := strings.Cut(part, "=")
		switch {
		case !ok:
		case k == "t":
			timestamp = v
		case k == "v1":
			signatures = append(signatures, v)
		}
	}
	ts, err := strconv.ParseInt(timestamp, 10, 64)
	if err != nil || len(timestamp) > 12 || len(signatures) == 0 {
		return false
	}
	if d := time.Since(time.Unix(ts, 0)); d > toleranceSeconds*time.Second || d < -toleranceSeconds*time.Second {
		return false
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(timestamp + "."))
	mac.Write(body)
	expected := hex.EncodeToString(mac.Sum(nil))
	for _, s := range signatures {
		if hmac.Equal([]byte(expected), []byte(s)) {
			return true
		}
	}
	return false
}

func stripe(w http.ResponseWriter, r *http.Request) {
	secret := os.Getenv("STRIPE_WEBHOOK_SECRET")
	if secret == "" {
		http.Error(w, "STRIPE_WEBHOOK_SECRET is not set", http.StatusInternalServerError)
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 5<<20))
	if err != nil || !verify(secret, body, r.Header.Get("Stripe-Signature")) {
		http.Error(w, "invalid signature", http.StatusBadRequest)
		return
	}

	var event struct {
		Type string `json:"type"`
		Data struct {
			Object map[string]any `json:"object"`
		} `json:"data"`
	}
	if err := json.Unmarshal(body, &event); err != nil {
		http.Error(w, "invalid JSON", http.StatusBadRequest)
		return
	}
	obj := event.Data.Object
	switch event.Type {
	case "checkout.session.completed":
		log.Println("checkout completed", obj["id"], obj["customer_email"]) // fulfil the order here
	case "invoice.payment_failed":
		log.Println("payment failed", obj["customer"])
	case "customer.subscription.deleted":
		log.Println("subscription cancelled", obj["id"])
	default:
		log.Println("unhandled event", event.Type)
	}

	// Answer quickly: Stripe retries on errors and timeouts.
	w.Header().Set("Content-Type", "application/json")
	io.WriteString(w, `{"received":true}`)
}
