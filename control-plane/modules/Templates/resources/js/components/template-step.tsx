import { toast } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type CreateOptionProps } from '@/lib/registry';
import { type Canvas } from '@/types';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { type Category, type TemplateSummary } from '../types';
import { ConfigureForm } from './configure-form';
import { TemplateGallery } from './template-gallery';

/**
 * Create picker → Template (registered in register.ts): gallery → configure → Deploy. The new card appears where the
 * picker was opened and its panel opens on the deploy stream.
 */
export function TemplateStep({ projectId, environmentSlug, position, onCreated }: CreateOptionProps) {
    const gallery = useJson<{ data: TemplateSummary[]; categories: Category[] }>('/templates', { unwrap: false });
    const [picked, setPicked] = useState<TemplateSummary | null>(null);

    if (picked) {
        return (
            <div className="grid">
                <button
                    type="button"
                    onClick={() => setPicked(null)}
                    className="text-fg-muted hover:text-fg flex items-center gap-1.5 px-4 pt-3 text-xs"
                >
                    <ArrowLeft className="size-3.5" aria-hidden /> All templates
                </button>
                <ConfigureForm
                    compact
                    template={picked}
                    target={{ projectId, environmentSlug }}
                    position={position}
                    onDeployed={async (result, warnings) => {
                        warnings.forEach((warning) => toast.warning(warning));
                        try {
                            const canvas = await requestJson<Canvas>(`/projects/${projectId}/${environmentSlug}/canvas`);
                            const card = canvas.services.find((service) => service.kind === 'site' && service.ref_id === result.site_id);
                            toast.success(`${result.name} created`, result.deployment_id ? 'First deploy started.' : undefined);
                            if (card) onCreated(card, result.deployment_id);
                            else if (result.panel_url) window.location.assign(result.panel_url);
                        } catch (error) {
                            toast.error(`${result.name} was created, but the canvas did not refresh`, errorMessage(error));
                        }
                    }}
                />
            </div>
        );
    }

    return (
        <div className="p-3">
            <TemplateGallery
                compact
                autoFocus
                templates={gallery.data?.data ?? null}
                categories={gallery.data?.categories ?? []}
                error={gallery.error}
                onPick={setPicked}
            />
        </div>
    );
}
