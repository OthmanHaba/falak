import { Button, Callout, Field, Tag, Textarea } from '@/components/falak';
import { errorMessage, HttpError, requestJson } from '@/lib/http';
import { CircleCheck } from 'lucide-react';
import { useState } from 'react';
import { type TemplateSummary } from '../types';
import { TemplateIconTile } from './template-icon';

export interface TemplateFiles {
    template_yaml: string;
    compose_yaml: string;
}

/** Validation problems from a 422 (the `template` key carries one message per problem). */
export async function problemsOf(error: unknown): Promise<string[]> {
    if (error instanceof HttpError && Object.keys(error.errors).length > 0) return Object.values(error.errors);

    return [errorMessage(error)];
}

/**
 * template.yaml + compose.yaml editor with a server-side "Validate" (same rules as the catalog). compose.yaml may stay
 * empty when template.yaml is a bundle with a `compose:` key.
 */
export function TemplateEditor({
    files,
    onChange,
    problems,
    onProblems,
    templateId = null,
}: {
    files: TemplateFiles;
    onChange: (files: TemplateFiles) => void;
    problems: string[];
    onProblems: (problems: string[]) => void;
    templateId?: string | null;
}) {
    const [preview, setPreview] = useState<TemplateSummary | null>(null);
    const [validating, setValidating] = useState(false);

    const validate = async () => {
        setValidating(true);
        setPreview(null);
        try {
            const response = await requestJson<{ data: TemplateSummary }>('/settings/templates/preview', 'POST', { ...files, id: templateId });
            setPreview(response.data);
            onProblems([]);
        } catch (error) {
            onProblems(await problemsOf(error));
        } finally {
            setValidating(false);
        }
    };

    const update = (next: TemplateFiles) => {
        setPreview(null);
        onChange(next);
    };

    return (
        <div className="grid gap-4">
            <Field
                label="template.yaml"
                hint="Name, slug, version, category, public services and inputs — or a bundle with the compose file under compose:."
            >
                <Textarea
                    value={files.template_yaml}
                    onChange={(event) => update({ ...files, template_yaml: event.target.value })}
                    rows={8}
                    mono
                    spellCheck={false}
                    placeholder={
                        'name: My app\nslug: my-app\nversion: 1.0.0\ndescription: …\ncategory: dev-tools\npublic:\n  - service: web\n    port: 8080'
                    }
                />
            </Field>
            <Field label="compose.yaml" hint="Leave empty for a bundle. Use ${VAR} for inputs and ${{ falak.url(service) }} for public URLs.">
                <Textarea
                    value={files.compose_yaml}
                    onChange={(event) => update({ ...files, compose_yaml: event.target.value })}
                    rows={8}
                    mono
                    spellCheck={false}
                    placeholder={'services:\n  web:\n    image: ghcr.io/acme/web:1.4.2\n    expose: ["8080"]'}
                />
            </Field>
            {problems.length > 0 && (
                <Callout tone="danger" title={problems.length === 1 ? '1 problem' : `${problems.length} problems`}>
                    <ul className="grid gap-0.5 font-mono text-[11px]">
                        {problems.map((problem) => (
                            <li key={problem}>{problem}</li>
                        ))}
                    </ul>
                </Callout>
            )}
            {preview && (
                <div className="border-success/30 bg-success-soft flex items-center gap-3 rounded-lg border p-3">
                    <TemplateIconTile icon={preview.icon} name={preview.name} size="sm" />
                    <div className="grid min-w-0 flex-1 gap-0.5">
                        <span className="text-fg flex items-center gap-1.5 text-sm font-medium">
                            {preview.name} <Tag mono>v{preview.version}</Tag>
                        </span>
                        <span className="text-fg-muted truncate text-xs">
                            {preview.services.length} services · public: {preview.public.map((entry) => `${entry.service}:${entry.port}`).join(', ')}
                        </span>
                    </div>
                    <CircleCheck className="text-success size-4 shrink-0" aria-label="Valid" />
                </div>
            )}
            <div>
                <Button size="sm" onClick={() => void validate()} loading={validating} disabled={!files.template_yaml.trim()}>
                    Validate
                </Button>
            </div>
        </div>
    );
}
