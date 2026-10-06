package builder

import (
	"slices"
	"testing"
)

func TestJobSecretsIncludeMaskedVariables(t *testing.T) {
	j := Job{
		Repo:   Repo{Token: "ghp_token123"},
		Env:    map[string]string{"NPM_TOKEN": "npm-secret-1", "NODE_ENV": "production"},
		Docker: &DockerSpec{BuildArgs: map[string]string{"SENTRY_AUTH_TOKEN": "sentry-secret", "APP_NAME": "shop"}},
		Mask:   []string{"NPM_TOKEN", "SENTRY_AUTH_TOKEN", "NOT_SET"},
	}
	got := j.Secrets()
	for _, want := range []string{"ghp_token123", "npm-secret-1", "sentry-secret"} {
		if !slices.Contains(got, want) {
			t.Errorf("%s missing from %v", want, got)
		}
	}
	for _, plain := range []string{"production", "shop"} {
		if slices.Contains(got, plain) {
			t.Errorf("%s is not secret", plain)
		}
	}
}
