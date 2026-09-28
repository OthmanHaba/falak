package facts

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestCloudPublicIPv4FromAWSIMDSv2(t *testing.T) {
	var sawToken bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodPut && r.URL.Path == "/latest/api/token" && r.Header.Get("X-aws-ec2-metadata-token-ttl-seconds") != "":
			w.Write([]byte("tok"))
		case r.URL.Path == "/latest/meta-data/public-ipv4" && r.Header.Get("X-aws-ec2-metadata-token") == "tok":
			sawToken = true
			w.Write([]byte("18.194.183.232\n"))
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	MetadataEndpoint, GCPMetadataEndpoint = srv.URL, srv.URL
	defer func() {
		MetadataEndpoint, GCPMetadataEndpoint = "http://169.254.169.254", "http://metadata.google.internal"
	}()
	resetCloudIPv4()
	defer resetCloudIPv4()

	ip := CloudPublicIPv4(context.Background())
	if ip == nil || *ip != "18.194.183.232" || !sawToken {
		t.Fatalf("got %v (token used: %v)", ip, sawToken)
	}
	srv.Close() // cached: no second lookup
	if again := CloudPublicIPv4(context.Background()); again == nil || *again != "18.194.183.232" {
		t.Fatal("not cached")
	}
}

func TestCloudPublicIPv4IgnoresPrivateAnswersAndNonClouds(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Metadata-Flavor") == "Google" {
			w.Write([]byte("10.0.0.5")) // never a public address
			return
		}
		http.NotFound(w, r) // no IMDS token, no Azure answer
	}))
	defer srv.Close()
	MetadataEndpoint, GCPMetadataEndpoint = srv.URL, srv.URL
	defer func() {
		MetadataEndpoint, GCPMetadataEndpoint = "http://169.254.169.254", "http://metadata.google.internal"
	}()
	resetCloudIPv4()
	defer resetCloudIPv4()

	if ip := CloudPublicIPv4(context.Background()); ip != nil {
		t.Fatalf("got %v", *ip)
	}
}
