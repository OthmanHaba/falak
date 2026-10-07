package dbhelper

import (
	"strings"
	"testing"
)

// MariaDB 11 clients require TLS by default, even on the private socket: falak-db's option files turn it off there
// (a drill's server has no certificate). MySQL's are left alone.
func TestMariaDBClientSkipsTLSOnTheSocket(t *testing.T) {
	b, err := newTestHelper(t, MariaDB).mysqlDefaults()
	if err != nil {
		t.Fatal(err)
	}
	client, _, _ := strings.Cut(string(b), "[xtrabackup]")
	if !strings.Contains(client, "skip-ssl") || strings.Count(string(b), "skip-ssl") != 1 {
		t.Errorf("mariadb defaults %q", b)
	}
	b, _ = newTestHelper(t, MySQL).mysqlDefaults()
	if strings.Contains(string(b), "skip-ssl") {
		t.Errorf("mysql defaults %q", b)
	}
}
