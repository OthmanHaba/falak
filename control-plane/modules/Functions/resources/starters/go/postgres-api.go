// A small notes API on Postgres. Set DATABASE_URL in Variables, e.g. ${{ postgres.DATABASE_URL }}
// (connect a database on the canvas to get the reference). Modules you import are resolved when you deploy.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"strconv"
	"sync"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

var Handler = routes()

var (
	poolMu sync.Mutex
	pool   *pgxpool.Pool
)

// db connects on first use (not at start, so the function deploys before DATABASE_URL is set) and creates the
// table.
func db(ctx context.Context) (*pgxpool.Pool, error) {
	poolMu.Lock()
	defer poolMu.Unlock()
	if pool != nil {
		return pool, nil
	}
	p, err := pgxpool.New(ctx, os.Getenv("DATABASE_URL"))
	if err != nil {
		return nil, err
	}
	if _, err := p.Exec(ctx, "create table if not exists notes (id serial primary key, body text not null, created_at timestamptz default now())"); err != nil {
		p.Close()
		return nil, err
	}
	pool = p
	return pool, nil
}

type note struct {
	ID        int    `json:"id"`
	Body      string `json:"body"`
	CreatedAt any    `json:"created_at"`
}

func routes() *http.ServeMux {
	mux := http.NewServeMux()

	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		with(w, r, func(p *pgxpool.Pool) (int, any, error) {
			var now any
			var version string
			err := p.QueryRow(r.Context(), "select now(), version()").Scan(&now, &version)
			return http.StatusOK, map[string]any{"now": now, "version": version}, err
		})
	})

	mux.HandleFunc("GET /notes", func(w http.ResponseWriter, r *http.Request) {
		with(w, r, func(p *pgxpool.Pool) (int, any, error) {
			rows, _ := p.Query(r.Context(), "select id, body, created_at from notes order by id desc limit 50")
			notes, err := pgx.CollectRows(rows, pgx.RowToStructByPos[note])
			return http.StatusOK, notes, err
		})
	})

	mux.HandleFunc("GET /notes/{id}", func(w http.ResponseWriter, r *http.Request) {
		with(w, r, func(p *pgxpool.Pool) (int, any, error) {
			rows, _ := p.Query(r.Context(), "select id, body, created_at from notes where id = $1", r.PathValue("id"))
			n, err := pgx.CollectExactlyOneRow(rows, pgx.RowToStructByPos[note])
			if errors.Is(err, pgx.ErrNoRows) {
				return http.StatusNotFound, map[string]string{"error": "not found"}, nil
			}
			return http.StatusOK, n, err
		})
	})

	mux.HandleFunc("POST /notes", func(w http.ResponseWriter, r *http.Request) {
		var in struct {
			Body string `json:"body"`
		}
		if err := json.NewDecoder(r.Body).Decode(&in); err != nil || in.Body == "" {
			writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": `send {"body": "…"}`})
			return
		}
		with(w, r, func(p *pgxpool.Pool) (int, any, error) {
			rows, _ := p.Query(r.Context(), "insert into notes (body) values ($1) returning id, body, created_at", in.Body)
			n, err := pgx.CollectExactlyOneRow(rows, pgx.RowToStructByPos[note])
			return http.StatusCreated, n, err
		})
	})

	mux.HandleFunc("DELETE /notes/{id}", func(w http.ResponseWriter, r *http.Request) {
		id, _ := strconv.Atoi(r.PathValue("id"))
		with(w, r, func(p *pgxpool.Pool) (int, any, error) {
			tag, err := p.Exec(r.Context(), "delete from notes where id = $1", id)
			if err == nil && tag.RowsAffected() == 0 {
				return http.StatusNotFound, map[string]string{"error": "not found"}, nil
			}
			return http.StatusNoContent, nil, err
		})
	})

	return mux
}

// with runs a query function and writes its result as JSON (a 500 with the error when it fails).
func with(w http.ResponseWriter, r *http.Request, fn func(*pgxpool.Pool) (int, any, error)) {
	p, err := db(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}
	status, body, err := fn(p)
	switch {
	case err != nil:
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
	case status == http.StatusNoContent:
		w.WriteHeader(status)
	default:
		writeJSON(w, status, body)
	}
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
