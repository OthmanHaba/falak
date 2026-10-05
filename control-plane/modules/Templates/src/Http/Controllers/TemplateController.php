<?php

namespace Falak\Templates\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Templates\Application\Actions\DeployTemplate;
use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\TemplateRepository;
use Falak\Templates\Application\Inputs\InputResolver;
use Falak\Templates\Domain\Template;
use Falak\Templates\Domain\TemplateSource;
use Falak\Templates\TemplatesServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The template gallery (/templates and the canvas Create picker) and template details for the configure form.
 */
final class TemplateController extends Controller
{
    use PresentsTemplates;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly TemplateRepository $templates,
    ) {}

    /**
     * GET /templates — the full-page gallery; JSON (catalog + custom summaries) for the Create picker.
     */
    public function index(Request $request, ProjectDirectory $projects): Response|JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, TemplatesServiceProvider::VIEW);

        $templates = array_map(fn (Template $t) => $this->summary($t), [...$this->templates->catalog(), ...$this->templates->custom($organizationId)]);

        if ($request->wantsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['data' => $templates, 'categories' => $this->categories()]);
        }

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
            'categories' => $this->categories(),
            'can' => [
                'deploy' => $this->access->can($request->user(), $organizationId, 'projects.manage') && $this->access->can($request->user(), $organizationId, 'sites.create'),
                'manage' => $this->access->can($request->user(), $organizationId, TemplatesServiceProvider::MANAGE),
            ],
        ]);
    }

    /**
     * GET /templates/{source}/{slug} — inputs (with freshly generated values), services, compose file.
     */
    public function show(Request $request, string $source, string $slug, InputResolver $inputs): JsonResponse
    {
        $template = $this->template($request, $source, $slug);

        return response()->json(['data' => $this->detail($template, $inputs->generated($template), DeployTemplate::testDomainBase())]);
    }

    /**
     * GET /templates/{source}/{slug}/generate/{key} — "Regenerate" in the configure form.
     */
    public function generate(Request $request, string $source, string $slug, string $key): JsonResponse
    {
        $input = $this->template($request, $source, $slug)->input($key);
        abort_if($input?->generate === null, 404);

        return response()->json(['data' => ['key' => $key, 'value' => $input->generate->generate()]]);
    }

    /**
     * GET /templates/catalog/{slug}/icon.svg — a catalog template's own icon (curated files only).
     */
    public function icon(string $slug, Catalog $catalog): BinaryFileResponse
    {
        $template = $catalog->find($slug);
        abort_if($template?->iconPath === null, 404);

        return response()->file($template->iconPath, [
            'Content-Type' => 'image/svg+xml',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function template(Request $request, string $source, string $slug): Template
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, TemplatesServiceProvider::VIEW);

        return $this->templates->find($organizationId, $slug, TemplateSource::from($source)) ?? abort(404, 'Template not found.');
    }
}
