import { AppShell, Button, PageHeader } from '@/components/falak';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';
import { NewVolumeDialog, VolumesTable } from '../components/volume-ui';
import { type CreationProps, type Volume } from '../types';

interface Props extends CreationProps {
    project: { id: string; name: string };
    environments: { id: string; name: string; slug: string }[];
    volumes: Volume[];
}

/** /projects/{p}/volumes: the volumes the project's services use, across its environments and servers. */
export default function Project({ project, environments, volumes, ...creation }: Props) {
    const [creating, setCreating] = useState(false);
    const canvas = environments[0] ? `/projects/${project.id}/${environments[0].slug}` : `/projects/${project.id}`;

    return (
        <AppShell
            breadcrumbs={[
                { title: project.name, href: canvas },
                { title: 'Volumes', href: `/projects/${project.id}/volumes` },
            ]}
        >
            <Head title={`Volumes · ${project.name}`} />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <div className="grid gap-3">
                    <Link href={canvas} className="text-fg-muted hover:text-fg flex w-fit items-center gap-1 text-xs">
                        <ArrowLeft className="size-3.5" aria-hidden /> Canvas
                    </Link>
                    <PageHeader
                        title="Volumes"
                        description={`Persistent data of ${project.name}'s services`}
                        actions={
                            creation.can.manage && (
                                <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)}>
                                    New volume
                                </Button>
                            )
                        }
                    />
                </div>
                <VolumesTable volumes={volumes} showServer onCreate={creation.can.manage ? () => setCreating(true) : undefined} />
            </div>
            <NewVolumeDialog open={creating} onOpenChange={setCreating} creation={creation} />
        </AppShell>
    );
}
