package api

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"strconv"
)

// Cloud Functions endpoints (`kiln fn …`). {site} is the function's site id or slug.
const (
	PathFunctions             = "/api/v1/functions"                                 // GET → {data:[FunctionSummary]}
	PathFunction              = "/api/v1/functions/{site}"                          // GET → {data:Function}
	PathFunctionDeploy        = "/api/v1/functions/{site}/deploy"                   // POST {files, message?, base_version_id?, force?} → {data:FunctionDeployResult}; 409 {message, head}
	PathFunctionVersions      = "/api/v1/functions/{site}/versions"                 // GET → {data:[FunctionVersion]}
	PathFunctionVersion       = "/api/v1/functions/{site}/versions/{number}"        // GET → {data:FunctionVersion (with files)}
	PathFunctionVersionDeploy = "/api/v1/functions/{site}/versions/{number}/deploy" // POST → 201 {data:{deployment_id}}
	PathFunctionScheduleRun   = "/api/v1/functions/{site}/schedules/{schedule}/run" // POST → 202 {data:{run_id}}
	PathFunctionRun           = "/api/v1/functions/{site}/runs/{run}"               // GET → {data:FunctionRun}
)

// FunctionRef is a short version reference.
type FunctionRef struct {
	Number    int    `json:"number"`
	Hash      string `json:"hash"`
	ShortHash string `json:"short_hash"`
}

// FunctionSummary is one row of GET /functions.
type FunctionSummary struct {
	ID         string       `json:"id"`
	Name       string       `json:"name"`
	Slug       string       `json:"slug"`
	Runtime    string       `json:"runtime"`
	Entrypoint string       `json:"entrypoint"`
	Live       *FunctionRef `json:"live"`
	URL        string       `json:"url,omitempty"`
}

// FunctionVersion is one immutable version of a function's code (Files only on single-version responses).
type FunctionVersion struct {
	ID         string            `json:"id"`
	Number     int               `json:"number"`
	Hash       string            `json:"hash"`
	ShortHash  string            `json:"short_hash"`
	Message    string            `json:"message,omitempty"`
	Author     string            `json:"author,omitempty"`
	Size       int               `json:"size"`
	CreatedAt  string            `json:"created_at"`
	Entrypoint string            `json:"entrypoint,omitempty"`
	Files      map[string]string `json:"files,omitempty"`
}

// FunctionSchedule is a schedule of a function.
type FunctionSchedule struct {
	ID         string `json:"id"`
	Key        string `json:"key"`
	Name       string `json:"name"`
	Expression string `json:"expression"`
	Timezone   string `json:"timezone"`
	Enabled    bool   `json:"enabled"`
}

// Function is GET /functions/{site}.
type Function struct {
	Site struct {
		ID   string `json:"id"`
		Name string `json:"name"`
		Slug string `json:"slug"`
	} `json:"site"`
	Runtime struct {
		Key      string `json:"key"`
		Label    string `json:"label"`
		Language string `json:"language"`
	} `json:"runtime"`
	Entrypoint string             `json:"entrypoint"`
	URL        string             `json:"url,omitempty"`
	Head       *FunctionVersion   `json:"head"`
	Live       *FunctionVersion   `json:"live"`
	Settings   map[string]any     `json:"settings,omitempty"`
	Schedules  []FunctionSchedule `json:"schedules"`
}

// FunctionDeployRequest is POST /functions/{site}/deploy.
type FunctionDeployRequest struct {
	Files         map[string]string `json:"files"`
	Message       string            `json:"message,omitempty"`
	BaseVersionID string            `json:"base_version_id,omitempty"`
	Force         bool              `json:"force,omitempty"`
}

// FunctionDeployResult is the deploy response.
type FunctionDeployResult struct {
	Version      FunctionVersion `json:"version"`
	Created      bool            `json:"created"`
	DeploymentID string          `json:"deployment_id"`
	Warnings     []string        `json:"warnings,omitempty"`
}

// FunctionRun is a schedule run (Run now).
type FunctionRun struct {
	Status     string `json:"status"`
	Finished   bool   `json:"finished"`
	ExitCode   *int   `json:"exit_code"`
	DurationMS *int64 `json:"duration_ms"`
	Error      string `json:"error,omitempty"`
	Output     string `json:"output"`
}

// ConflictError: someone deployed a newer version than the base (HTTP 409).
type ConflictError struct {
	Message string
	Head    FunctionVersion
}

func (e *ConflictError) Error() string { return e.Message }

func (c *Client) Functions(ctx context.Context) ([]FunctionSummary, error) {
	env, err := get[[]FunctionSummary](ctx, c, PathFunctions, nil)
	return env.Data, err
}

func (c *Client) Function(ctx context.Context, fn string) (Function, error) {
	env, err := get[Function](ctx, c, Path(PathFunction, "site", fn), nil)
	return env.Data, err
}

func (c *Client) FunctionVersions(ctx context.Context, fn string) ([]FunctionVersion, error) {
	env, err := get[[]FunctionVersion](ctx, c, Path(PathFunctionVersions, "site", fn), nil)
	return env.Data, err
}

func (c *Client) FunctionVersion(ctx context.Context, fn string, number int) (FunctionVersion, error) {
	env, err := get[FunctionVersion](ctx, c, Path(PathFunctionVersion, "site", fn, "number", strconv.Itoa(number)), nil)
	return env.Data, err
}

// FunctionDeploy saves the files as a new version and deploys it; a stale base returns *ConflictError.
func (c *Client) FunctionDeploy(ctx context.Context, fn string, r FunctionDeployRequest) (FunctionDeployResult, error) {
	var env struct {
		Data     FunctionDeployResult `json:"data"`
		Warnings []string             `json:"warnings"`
	}
	err := c.Do(ctx, http.MethodPost, Path(PathFunctionDeploy, "site", fn), nil, r, &env)
	var apiErr *Error
	if errors.As(err, &apiErr) && apiErr.Status == http.StatusConflict {
		var body struct {
			Message string          `json:"message"`
			Head    FunctionVersion `json:"head"`
		}
		if json.Unmarshal(apiErr.Body, &body) == nil && body.Head.Number > 0 {
			return FunctionDeployResult{}, &ConflictError{Message: body.Message, Head: body.Head}
		}
	}
	env.Data.Warnings = env.Warnings
	return env.Data, err
}

func (c *Client) FunctionRollback(ctx context.Context, fn string, number int) (string, error) {
	var env Envelope[struct {
		DeploymentID string `json:"deployment_id"`
	}]
	err := c.Do(ctx, http.MethodPost, Path(PathFunctionVersionDeploy, "site", fn, "number", strconv.Itoa(number)), nil, nil, &env)
	return env.Data.DeploymentID, err
}

func (c *Client) FunctionRun(ctx context.Context, fn, schedule string) (string, error) {
	var env Envelope[struct {
		RunID string `json:"run_id"`
	}]
	err := c.Do(ctx, http.MethodPost, Path(PathFunctionScheduleRun, "site", fn, "schedule", schedule), nil, nil, &env)
	return env.Data.RunID, err
}

func (c *Client) FunctionRunStatus(ctx context.Context, fn, run string) (FunctionRun, error) {
	env, err := get[FunctionRun](ctx, c, Path(PathFunctionRun, "site", fn, "run", run), nil)
	return env.Data, err
}
