import { Button, Section } from '@/components/falak';
import { type ServerSectionProps } from '@/lib/registry';
import { Link } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';

/** Server page: the way into "This server is gone" (admins). */
export function ServerRecoveryCard({ serverId }: ServerSectionProps) {
    return (
        <Section
            title="Disaster recovery"
            description="If this server is lost for good, bring its databases (from their latest backups), volumes, services and domains back on another server. A dry run shows the plan and the data loss first."
        >
            <Button asChild icon={<LifeBuoy />}>
                <Link href={`/servers/${serverId}/recovery`}>This server is gone…</Link>
            </Button>
        </Section>
    );
}
