/** Shapes of the Templates module's JSON (PresentsTemplates.php). */

export type TemplateSource = 'catalog' | 'custom';

export type TemplateIconRef = { type: 'brand'; key: string } | { type: 'url'; url: string } | null;

export interface TemplateSummary {
    slug: string;
    id: string | null;
    source: TemplateSource;
    name: string;
    description: string;
    version: string;
    category: string;
    category_label: string;
    icon: TemplateIconRef;
    docs: string | null;
    popular: boolean;
    stateful: boolean;
    min_memory_mb: number | null;
    tags: string[];
    services: { name: string; image: string }[];
    public: { service: string; port: number }[];
}

export type InputType = 'string' | 'secret' | 'email' | 'number' | 'boolean' | 'select' | 'domain';

export interface TemplateInput {
    key: string;
    type: InputType;
    label: string;
    description?: string;
    default?: string;
    generate?: string;
    options?: string[];
    required: boolean;
    placeholder?: string;
    secret: boolean;
    generated: boolean;
}

export interface TemplateDetail extends TemplateSummary {
    inputs: TemplateInput[];
    /** Freshly generated values for generated inputs (shown masked; Regenerate replaces them). */
    generated: Record<string, string>;
    compose: string;
    template_yaml: string;
    /** FALAK_TEST_DOMAIN base, null when not configured (then every public service needs a domain). */
    test_domain: string | null;
}

export interface Category {
    value: string;
    label: string;
}

export interface DeployResult {
    site_id: string;
    name: string;
    service_id: string | null;
    deployment_id: string | null;
    domains: Record<string, string>;
    panel_url: string | null;
}

export interface CustomTemplateRow {
    id: string;
    slug: string;
    name: string;
    version: string;
    revision: number;
    description: string;
    category: string;
    summary: TemplateSummary | null;
    updated_at: string;
}

export interface CustomTemplateDetail extends CustomTemplateRow {
    template_yaml: string;
    compose_yaml: string;
    revisions: { revision: number; version: string; created_at: string }[];
}

export function detailUrl(template: Pick<TemplateSummary, 'source' | 'slug'>): string {
    return `/templates/${template.source}/${template.slug}`;
}

/** The Falak slug a name becomes (CreateSite::slug), for previewing test domains. */
export function slugify(value: string): string {
    return (
        value
            .toLowerCase()
            .normalize('NFKD')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 44) || 'site'
    );
}
