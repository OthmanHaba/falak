import { Button } from '@/components/kiln/button';
import { KilnMark } from '@/components/kiln/logo';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth, registration } = usePage<SharedData>().props;

    return (
        <div className="bg-bg relative flex min-h-svh flex-col items-center justify-center px-4">
            <Head title="Welcome" />
            <div
                aria-hidden
                className="bg-dotted pointer-events-none absolute inset-0 [mask-image:radial-gradient(ellipse_60%_55%_at_50%_45%,black,transparent)]"
            />
            <main className="relative grid max-w-lg justify-items-center gap-6 text-center">
                <KilnMark size={40} />
                <div className="grid gap-2">
                    <h1 className="text-fg text-xl font-semibold">Kiln</h1>
                    <p className="text-fg-muted text-base">Self-hosted servers, deployments and observability for your apps.</p>
                </div>
                <div className="flex gap-2">
                    {auth.user ? (
                        <Button variant="primary" asChild>
                            <Link href="/projects">Open Kiln</Link>
                        </Button>
                    ) : (
                        <>
                            <Button variant="primary" asChild>
                                <Link href={route('login')}>Log in</Link>
                            </Button>
                            {registration !== 'closed' && (
                                <Button asChild>
                                    <Link href={route('register')}>Create account</Link>
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </main>
        </div>
    );
}
