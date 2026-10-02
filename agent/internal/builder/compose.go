package builder

import (
	"context"
	"errors"
	"fmt"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"
)

// ComposeSpec configures a compose build (docker mode): every service with `build:` in the repository's
// compose file is built and pushed as <image_prefix>/<service>:<tag>; the result pins each by digest.
type ComposeSpec struct {
	File        string            `json:"file,omitempty"`       // relative to the app root; "" = compose.yaml|compose.yml|docker-compose.yml|docker-compose.yaml
	Files       []string          `json:"files,omitempty"`      // several files merged in -f order (wins over File)
	Profiles    []string          `json:"profiles,omitempty"`   // active profiles; services of other profiles are dropped
	ImagePrefix string            `json:"image_prefix"`         // e.g. registry.kiln.local/kiln/shop
	Tag         string            `json:"tag,omitempty"`        // default: the build id (lowercase)
	BuildArgs   map[string]string `json:"build_args,omitempty"` // merged under each service's build.args
	Platforms   []string          `json:"platforms,omitempty"`
	Registry    *RegistryAuth     `json:"registry,omitempty"`
	Push        *bool             `json:"push,omitempty"` // default true
	Engine      string            `json:"engine,omitempty"`
}

// ComposeResult is the result of a compose build.
type ComposeResult struct {
	File    string                 `json:"file"`    // the (first) compose file used (relative to the app root)
	Files   []string               `json:"files"`   // every compose file read (-f files, includes, extends)
	Content string                 `json:"content"` // the merged project, paths rebased to the app root (the control plane renders it)
	Images  map[string]ImageResult `json:"images"`  // service → built image (pinned by digest)
	// Assets are the repository files the project reads at runtime (bind-mount sources, env_file, configs and
	// secrets `file:`), shipped with each release. Paths the repository doesn't have are listed in Missing.
	Assets  []ComposeAsset `json:"assets,omitempty"`
	Missing []string       `json:"missing,omitempty"`
}

// ComposeAsset is one repository file a compose project needs on the servers.
type ComposeAsset struct {
	Path    string `json:"path"`    // relative to the app root
	Content string `json:"content"` // base64
	Mode    int    `json:"mode"`    // 0644 or 0755
}

// Limits of the files shipped with a compose release.
const (
	maxComposeAssets      = 200
	maxComposeAssetBytes  = 1 << 20
	maxComposeAssetsBytes = 2 << 20
)

// DefaultComposeFiles are tried in order when ComposeSpec.File is empty (Compose's own lookup order).
var DefaultComposeFiles = []string{"compose.yaml", "compose.yml", "docker-compose.yml", "docker-compose.yaml"}

var composeServiceRe = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]*$`)

// ComposeBuild is one `build:` section of a compose service, resolved relative to the app root.
type ComposeBuild struct {
	Service    string
	Context    string // relative to the app root ("." for the root)
	Dockerfile string // relative to the app root
	Target     string
	Args       map[string]string
}

// ParseComposeBuilds returns the services of a compose file that have a `build:` section (sorted).
func ParseComposeBuilds(content []byte) ([]ComposeBuild, error) {
	var doc struct {
		Services map[string]struct {
			Build yaml.Node `yaml:"build"`
		} `yaml:"services"`
	}
	if err := yaml.Unmarshal(content, &doc); err != nil {
		return nil, fmt.Errorf("compose file: %w", err)
	}
	var out []ComposeBuild
	for name, svc := range doc.Services {
		if svc.Build.Kind == 0 {
			continue
		}
		if !composeServiceRe.MatchString(name) {
			return nil, fmt.Errorf("compose service %q: invalid name", name)
		}
		b := ComposeBuild{Service: name, Context: ".", Args: map[string]string{}}
		var dockerfile string
		switch svc.Build.Kind {
		case yaml.ScalarNode:
			b.Context = svc.Build.Value
		case yaml.MappingNode:
			var m struct {
				Context          string    `yaml:"context"`
				Dockerfile       string    `yaml:"dockerfile"`
				DockerfileInline string    `yaml:"dockerfile_inline"`
				Target           string    `yaml:"target"`
				Args             yaml.Node `yaml:"args"`
			}
			if err := svc.Build.Decode(&m); err != nil {
				return nil, fmt.Errorf("compose service %s: build: %w", name, err)
			}
			if m.DockerfileInline != "" {
				return nil, fmt.Errorf("compose service %s: build.dockerfile_inline is not supported", name)
			}
			if m.Context != "" {
				b.Context = m.Context
			}
			dockerfile, b.Target = m.Dockerfile, m.Target
			switch m.Args.Kind {
			case yaml.MappingNode:
				var args map[string]*string
				if err := m.Args.Decode(&args); err != nil {
					return nil, fmt.Errorf("compose service %s: build.args: %w", name, err)
				}
				for k, v := range args {
					if v != nil {
						b.Args[k] = *v
					}
				}
			case yaml.SequenceNode:
				var args []string
				if err := m.Args.Decode(&args); err != nil {
					return nil, fmt.Errorf("compose service %s: build.args: %w", name, err)
				}
				for _, a := range args {
					if k, v, ok := strings.Cut(a, "="); ok {
						b.Args[k] = v
					}
				}
			}
		default:
			return nil, fmt.Errorf("compose service %s: unsupported build section", name)
		}
		if strings.Contains(b.Context, "://") || strings.HasPrefix(b.Context, "git@") {
			return nil, fmt.Errorf("compose service %s: remote build contexts are not supported", name)
		}
		ctxDir := path.Clean(filepath.ToSlash(b.Context))
		if path.IsAbs(ctxDir) || ctxDir == ".." || strings.HasPrefix(ctxDir, "../") {
			return nil, fmt.Errorf("compose service %s: build context %q must stay inside the repository", name, b.Context)
		}
		b.Context = ctxDir
		if dockerfile == "" {
			dockerfile = "Dockerfile"
		}
		df := path.Clean(path.Join(ctxDir, filepath.ToSlash(dockerfile)))
		if path.IsAbs(dockerfile) || df == ".." || strings.HasPrefix(df, "../") {
			return nil, fmt.Errorf("compose service %s: dockerfile %q must stay inside the repository", name, dockerfile)
		}
		b.Dockerfile = df
		out = append(out, b)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Service < out[j].Service })
	return out, nil
}

// findComposeFile resolves spec.File (or the default names) inside the app root.
func findComposeFile(app, file string) (string, error) {
	candidates := DefaultComposeFiles
	if file != "" {
		clean := path.Clean(filepath.ToSlash(file))
		if path.IsAbs(clean) || clean == ".." || strings.HasPrefix(clean, "../") {
			return "", fmt.Errorf("compose.file %q must stay inside the repository", file)
		}
		candidates = []string{clean}
	}
	for _, c := range candidates {
		if fileExists(filepath.Join(app, filepath.FromSlash(c))) {
			return c, nil
		}
	}
	if file != "" {
		return "", fmt.Errorf("compose file %q not found in the repository", file)
	}
	return "", fmt.Errorf("no compose file found (tried %s)", strings.Join(DefaultComposeFiles, ", "))
}

func (b *Builder) buildCompose(ctx context.Context, j *job) (*ComposeResult, error) {
	spec := *j.Compose
	files := spec.Files
	if len(files) == 0 {
		file, err := findComposeFile(j.app, spec.File)
		if err != nil {
			return nil, err
		}
		files = []string{file}
	}
	doc, read, err := LoadComposeProject(func(rel string) ([]byte, error) { return readRepoFile(j.app, rel) }, files, spec.Profiles)
	if err != nil {
		return nil, err
	}
	content, err := yaml.Marshal(doc)
	if err != nil {
		return nil, err
	}
	builds, err := ParseComposeBuilds(content)
	if err != nil {
		return nil, err
	}
	res := &ComposeResult{File: files[0], Files: read, Content: string(content), Images: map[string]ImageResult{}}
	if res.Assets, res.Missing, err = collectComposeAssets(j.app, ComposeReferences(doc)); err != nil {
		return nil, err
	}
	if len(files) > 1 || len(spec.Profiles) > 0 {
		j.logf("Compose project %s (profiles: %s)", strings.Join(files, " + "), strings.Join(spec.Profiles, ", "))
	}
	if len(res.Assets) > 0 {
		j.logf("Shipping %d repository file(s) the project mounts or reads", len(res.Assets))
	}
	j.logf("Compose file %s: %d service(s) to build", files[0], len(builds))
	tag := spec.Tag
	if tag == "" {
		tag = strings.ToLower(j.ID)
	}
	for i, cb := range builds {
		args := map[string]string{}
		for k, v := range spec.BuildArgs {
			args[k] = v
		}
		for k, v := range cb.Args {
			args[k] = v
		}
		ds := DockerSpec{
			Image:      strings.TrimRight(spec.ImagePrefix, "/") + "/" + strings.ToLower(cb.Service) + ":" + tag,
			Dockerfile: cb.Dockerfile,
			Context:    cb.Context,
			Target:     cb.Target,
			BuildArgs:  args,
			Platforms:  spec.Platforms,
			Registry:   spec.Registry,
			Push:       spec.Push,
			Engine:     spec.Engine,
		}
		j.logf("Building service %s (%d/%d)", cb.Service, i+1, len(builds))
		j.Docker, j.cacheSuffix = &ds, cb.Service
		img, err := b.buildDocker(ctx, j)
		if err != nil {
			return nil, fmt.Errorf("service %s: %w", cb.Service, err)
		}
		if img == nil {
			return nil, errors.New("service " + cb.Service + ": no image")
		}
		res.Images[cb.Service] = *img
		j.st.Progress(0.2 + 0.7*float64(i+1)/float64(len(builds)))
	}
	j.Docker, j.cacheSuffix = nil, ""
	return res, nil
}
