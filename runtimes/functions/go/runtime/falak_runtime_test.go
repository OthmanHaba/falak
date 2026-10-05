package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"testing"
)

// The function's main() is generated at install time; tests need one to build the package.
func main() {}

func TestDefaultTransportStaysAnHTTPTransport(t *testing.T) {
	falakInstrumentHTTP()
	if _, ok := http.DefaultTransport.(*http.Transport); !ok {
		t.Fatalf("DefaultTransport is %T", http.DefaultTransport)
	}
	_ = http.DefaultTransport.(*http.Transport).Clone()
	if _, ok := http.DefaultClient.Transport.(*falakTransport); !ok {
		t.Fatalf("DefaultClient.Transport is %T", http.DefaultClient.Transport)
	}
}

// The traced handler still hands out the connection (websockets) and streams with ReadFrom.
func TestRecorderPassesThroughHijackAndReadFrom(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /ws", func(w http.ResponseWriter, r *http.Request) {
		conn, rw, err := w.(http.Hijacker).Hijack()
		if err != nil {
			t.Error(err)
			return
		}
		defer conn.Close()
		rw.WriteString("HTTP/1.1 101 Switching Protocols\r\nUpgrade: test\r\nConnection: Upgrade\r\n\r\nhello")
		rw.Flush()
	})
	mux.HandleFunc("GET /copy", func(w http.ResponseWriter, r *http.Request) {
		if _, err := w.(io.ReaderFrom).ReadFrom(strings.NewReader("copied")); err != nil {
			t.Error(err)
		}
	})
	srv := httptest.NewServer(falakTrace(mux))
	defer srv.Close()

	conn, err := net.Dial("tcp", srv.Listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	fmt.Fprint(conn, "GET /ws HTTP/1.1\r\nHost: x\r\nConnection: Upgrade\r\nUpgrade: test\r\n\r\n")
	res, err := http.ReadResponse(bufio.NewReader(conn), nil)
	if err != nil || res.StatusCode != http.StatusSwitchingProtocols {
		t.Fatalf("hijack: %v %v", res, err)
	}

	res, err = http.Get(srv.URL + "/copy")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := io.ReadAll(res.Body)
	res.Body.Close()
	if string(b) != "copied" {
		t.Fatalf("readfrom: %q", b)
	}
}

func TestRedaction(t *testing.T) {
	b, err := os.ReadFile("../../tests/redact-cases.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases struct{ Paths, URLs, Texts [][2]string }
	if err := json.Unmarshal(b, &cases); err != nil {
		t.Fatal(err)
	}
	for _, c := range cases.Paths {
		if got := falakRedactPath(c[0]); got != c[1] {
			t.Errorf("falakRedactPath(%q) = %q, want %q", c[0], got, c[1])
		}
	}
	for _, c := range cases.Texts {
		if got := falakRedactText(c[0]); got != c[1] {
			t.Errorf("falakRedactText(%q) = %q, want %q", c[0], got, c[1])
		}
	}
	for _, c := range cases.URLs {
		u, err := url.Parse(c[0])
		if err != nil {
			t.Fatal(err)
		}
		if got := falakSafeURL(u); got != c[1] {
			t.Errorf("falakSafeURL(%q) = %q, want %q", c[0], got, c[1])
		}
	}
	if len(cases.Paths) == 0 || len(cases.URLs) == 0 {
		t.Fatal("no cases")
	}
}
