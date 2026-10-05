import { Button } from '@/components/falak/button';
import { RelativeTime } from '@/components/falak/relative-time';
import AuthLayout, { AuthLink } from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';

interface InvitationProps {
    token: string;
    invitation: {
        organization: string | null;
        role: string;
        invited_by: string | null;
        email_matches: boolean;
        email: string;
        expires_at: string;
    } | null;
}

export default function ShowInvitation({ token, invitation }: InvitationProps) {
    const { post, processing, errors } = useForm({});
    const error = (errors as Record<string, string | undefined>).invitation;

    if (!invitation) {
        return (
            <AuthLayout
                title="Invitation unavailable"
                description="This invitation is invalid, has already been used, or has expired. Ask for a new one."
                footer={<AuthLink href={'/projects'}>Go to Falak</AuthLink>}
            >
                <Head title="Invitation" />
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title={`Join ${invitation.organization ?? 'the organization'}`}
            description={
                <>
                    {invitation.invited_by ?? 'Someone'} invited <span className="text-fg">{invitation.email}</span> as{' '}
                    <span className="text-fg font-medium">{invitation.role}</span>. Expires <RelativeTime value={invitation.expires_at} />.
                </>
            }
        >
            <Head title="Invitation" />
            {!invitation.email_matches && (
                <p role="alert" className="border-warning/40 bg-warning-soft text-fg rounded-md border px-3 py-2 text-sm">
                    This invitation was sent to {invitation.email}, but you're signed in with a different address. Log in with that account to accept
                    it.
                </p>
            )}
            {error && (
                <p role="alert" className="text-danger text-sm">
                    {error}
                </p>
            )}
            <div className="grid gap-2">
                <Button
                    variant="primary"
                    className="w-full"
                    loading={processing}
                    disabled={!invitation.email_matches}
                    onClick={() => post(route('invitations.accept', token))}
                >
                    Accept invitation
                </Button>
                <Button variant="ghost" className="w-full" asChild>
                    <Link href={'/projects'}>Not now</Link>
                </Button>
            </div>
        </AuthLayout>
    );
}
