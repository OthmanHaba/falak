// Contact form backend: your site POSTs the form here and it is emailed to you through Resend (resend.com).
// Variables:
//
//	RESEND_API_KEY   your Resend API key
//	CONTACT_TO       where messages go, e.g. you@example.com
//	CONTACT_FROM     a sender on a domain verified in Resend, e.g. "Website <hello@example.com>"
//	ALLOWED_ORIGIN   your site's origin for CORS, e.g. https://example.com (default *)
//	REDIRECT_URL     where plain HTML form posts are sent afterwards (optional)
package main

import (
	"bytes"
	"encoding/json"
	"html"
	"io"
	"log"
	"net/http"
	"os"
	"regexp"
	"strings"
	"time"
)

var email = regexp.MustCompile(`^[^@\s]+@[^@\s]+\.[^@\s]+$`)

var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("OPTIONS /contact", func(w http.ResponseWriter, r *http.Request) {
		cors(w)
		w.Header().Set("Access-Control-Allow-Methods", "POST")
		w.Header().Set("Access-Control-Allow-Headers", "*")
		w.WriteHeader(http.StatusNoContent)
	})
	mux.HandleFunc("POST /contact", contact)
	mux.HandleFunc("GET /thanks", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		io.WriteString(w, "<p>Thanks! Your message was sent.</p>")
	})
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		io.WriteString(w, "Contact form backend: POST /contact\n")
	})
	return mux
}

func cors(w http.ResponseWriter) {
	origin := os.Getenv("ALLOWED_ORIGIN")
	if origin == "" {
		origin = "*"
	}
	w.Header().Set("Access-Control-Allow-Origin", origin)
}

func cut(s string, n int) string {
	s = strings.TrimSpace(s)
	if len(s) > n {
		return s[:n]
	}
	return s
}

func contact(w http.ResponseWriter, r *http.Request) {
	cors(w)
	isJSON := strings.Contains(r.Header.Get("Content-Type"), "application/json")
	form := map[string]string{}
	if isJSON {
		var in map[string]any
		_ = json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&in)
		for k, v := range in {
			if s, ok := v.(string); ok {
				form[k] = s
			}
		}
	} else if err := r.ParseForm(); err == nil {
		for k := range r.PostForm {
			form[k] = r.PostForm.Get(k)
		}
	}
	name, from, message := cut(form["name"], 200), cut(form["email"], 200), cut(form["message"], 10_000)

	done := func() {
		if isJSON {
			w.Header().Set("Content-Type", "application/json")
			io.WriteString(w, `{"ok":true}`)
			return
		}
		redirect := os.Getenv("REDIRECT_URL")
		if redirect == "" {
			redirect = "/thanks"
		}
		http.Redirect(w, r, redirect, http.StatusSeeOther)
	}

	if form["website"] != "" { // honeypot: bots fill it, pretend it worked
		done()
		return
	}
	if name == "" || !email.MatchString(from) || len(message) < 2 {
		http.Error(w, "name, a valid email and a message are required", http.StatusUnprocessableEntity)
		return
	}
	if os.Getenv("RESEND_API_KEY") == "" || os.Getenv("CONTACT_TO") == "" || os.Getenv("CONTACT_FROM") == "" {
		http.Error(w, "set RESEND_API_KEY, CONTACT_TO and CONTACT_FROM", http.StatusInternalServerError)
		return
	}

	body, _ := json.Marshal(map[string]any{
		"from":     os.Getenv("CONTACT_FROM"),
		"to":       os.Getenv("CONTACT_TO"),
		"reply_to": from,
		"subject":  "Contact form: " + name,
		"html": "<p><strong>" + html.EscapeString(name) + "</strong> &lt;" + html.EscapeString(from) + "&gt; wrote:</p><p>" +
			strings.ReplaceAll(html.EscapeString(message), "\n", "<br>") + "</p>",
	})
	req, _ := http.NewRequestWithContext(r.Context(), http.MethodPost, "https://api.resend.com/emails", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+os.Getenv("RESEND_API_KEY"))
	req.Header.Set("Content-Type", "application/json")
	res, err := (&http.Client{Transport: http.DefaultClient.Transport, Timeout: 15 * time.Second}).Do(req)
	if err != nil {
		log.Println("resend:", err)
		http.Error(w, "could not send the message", http.StatusBadGateway)
		return
	}
	defer res.Body.Close()
	if res.StatusCode >= 400 {
		b, _ := io.ReadAll(io.LimitReader(res.Body, 2048))
		log.Println("resend", res.StatusCode, string(b))
		http.Error(w, "could not send the message", http.StatusBadGateway)
		return
	}
	done()
}
