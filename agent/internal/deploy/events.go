package deploy

import (
	"time"

	"github.com/kiln/agent/internal/obs"
)

// Deployment lifecycle statuses and phases (observability contract: Loki query
// `{service_name="kiln-agent"} | kiln_event_type="deployment" | kiln_deployment_status="failed"`).
const (
	StatusStarted    = "started"
	StatusSucceeded  = "succeeded"
	StatusFailed     = "failed"
	StatusRolledBack = "rolled_back"

	PhaseFetch    = "fetch"
	PhasePrepare  = "prepare"
	PhaseHook     = "hook"
	PhaseActivate = "activate"
	PhaseRollback = "rollback"
)

// EventService is the resource service.name of deployment events.
const EventService = "kiln-agent"

// lifecycle describes one deployment step for event emission.
type lifecycle struct {
	site, phase, releaseID, hook string
	ctx                          *Context
}

// emit sends one deployment lifecycle log record. Failures are ERROR with the error message as body.
func (d *Deployer) emit(l lifecycle, status string, err error) {
	if d.o.Events == nil {
		return
	}
	c := l.ctx
	if c == nil {
		c = &Context{}
	}
	attrs := map[string]string{
		"kiln.event.type":        "deployment",
		"kiln.deployment.status": status,
		"kiln.deployment.phase":  l.phase,
		"kiln.site":              l.site,
	}
	set := func(k, v string) {
		if v != "" {
			attrs[k] = v
		}
	}
	set("kiln.deployment.id", c.DeploymentID)
	set("kiln.release.id", l.releaseID)
	set("kiln.deployment.hook", l.hook)
	set("vcs.ref.head.revision", c.Commit)
	set("vcs.ref.head.name", c.Branch)
	set("kiln.deployment.trigger", c.Trigger)
	sev, body := "INFO", "deployment "+status+" ("+l.phase+")"
	if err != nil {
		sev, body = "ERROR", err.Error()
	}
	d.o.Events.EmitLog(obs.LogRecord{
		Time: time.Now(), Severity: sev, Body: body,
		// service.name stays kiln-agent (dashboards select on it); kiln.site.id goes on the resource,
		// where Loki indexes it. Site is still passed so the slug → id mapping is a fallback.
		Service: EventService, Site: l.site, SiteID: c.SiteID,
		Attrs: attrs,
	})
}

// failed emits a failed event when *errp is non-nil (use with defer).
func (d *Deployer) failed(l lifecycle, errp *error) {
	if *errp != nil {
		d.emit(l, StatusFailed, *errp)
	}
}
