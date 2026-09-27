import { Button, Callout, Dialog, Skeleton, toast } from '@/components/kiln';
import { requestJson } from '@/lib/http';
import { type ServiceActionDialogProps } from '@/lib/registry';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { type CustomTemplateRow } from '../types';
import { TemplateEditor, problemsOf, type TemplateFiles } from './template-editor';

/**
 * Service panel ⋯ → Save as template (compose sites): Kiln drafts template.yaml + compose.yaml from the site (secret
 * values become generated inputs, domains become Kiln placeholders); the user reviews and saves it.
 */
export function SaveAsTemplateDialog({ ctx, open, onOpenChange }: ServiceActionDialogProps) {
    const [files, setFiles] = useState<TemplateFiles | null>(null);
    const [problems, setProblems] = useState<string[]>([]);
    const [loadError, setLoadError] = useState<string[] | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setFiles(null);
        setLoadError(null);
        requestJson<{ data: { template_yaml: string; compose_yaml: string; problems: string[] } }>(
            `/settings/templates/from-site/${ctx.service.ref_id}`,
            'POST',
        )
            .then((response) => {
                setFiles({ template_yaml: response.data.template_yaml, compose_yaml: response.data.compose_yaml });
                setProblems(response.data.problems);
            })
            .catch(async (error: unknown) => setLoadError(await problemsOf(error)));
    }, [open, ctx.service.ref_id]);

    const save = async () => {
        if (!files) return;
        setSaving(true);
        try {
            const response = await requestJson<{ data: CustomTemplateRow }>('/settings/templates', 'POST', files);
            toast.success(`Saved ${response.data.name} as a template`, 'Find it in the Create picker and under Settings → Templates.');
            onOpenChange(false);
        } catch (error) {
            setProblems(await problemsOf(error));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            className="grid-rows-[auto_minmax(0,1fr)_auto]"
            title={`Save ${ctx.service.name} as a template`}
            description="Secret values are not copied: they become inputs generated fresh for every deploy. Review before saving."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={() => void save()} loading={saving} disabled={!files}>
                        Save template
                    </Button>
                </>
            }
        >
            {loadError ? (
                <Callout tone="warning" title="This service cannot be saved as a template">
                    {loadError.join(' ')}
                </Callout>
            ) : !files ? (
                <div className="grid gap-3">
                    <Skeleton className="h-40" />
                    <Skeleton className="h-40" />
                </div>
            ) : (
                <div className="grid gap-3">
                    <TemplateEditor files={files} onChange={setFiles} problems={problems} onProblems={setProblems} />
                    <p className="text-fg-faint text-xs">
                        Saved templates are listed under{' '}
                        <Link href="/settings/templates" className="text-primary hover:underline">
                            Settings → Templates
                        </Link>
                        .
                    </p>
                </div>
            )}
        </Dialog>
    );
}
