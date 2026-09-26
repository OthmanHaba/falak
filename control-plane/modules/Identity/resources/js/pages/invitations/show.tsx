import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Invitation', href: '#' }];

export default function ShowInvitation({ token, invitation }: InvitationProps) {
    const { post, processing, errors } = useForm({});

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Invitation" />
            <div className="flex justify-center px-4 py-10">
                <Card className="w-full max-w-md">
                    {invitation ? (
                        <>
                            <CardHeader>
                                <CardTitle>Join {invitation.organization}</CardTitle>
                                <CardDescription>
                                    {invitation.invited_by ?? 'Someone'} invited {invitation.email} as <strong>{invitation.role}</strong>. Expires{' '}
                                    {formatDistanceToNow(new Date(invitation.expires_at), { addSuffix: true })}.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {!invitation.email_matches && (
                                    <p className="text-sm text-amber-700 dark:text-amber-400">
                                        This invitation was sent to {invitation.email}, but you are signed in with a different address. Log in with
                                        that account to accept it.
                                    </p>
                                )}
                                <InputError message={(errors as Record<string, string>).invitation} />
                            </CardContent>
                            <CardFooter className="gap-2">
                                <Button disabled={processing || !invitation.email_matches} onClick={() => post(route('invitations.accept', token))}>
                                    Accept invitation
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={route('dashboard')}>Not now</Link>
                                </Button>
                            </CardFooter>
                        </>
                    ) : (
                        <CardHeader>
                            <CardTitle>Invitation unavailable</CardTitle>
                            <CardDescription>This invitation is invalid, has already been used, or has expired. Ask for a new one.</CardDescription>
                        </CardHeader>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
