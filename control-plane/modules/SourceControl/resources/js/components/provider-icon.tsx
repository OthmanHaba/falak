import { GitBranch, Github, Gitlab, KeyRound } from 'lucide-react';
import { type ProviderValue } from '../types';

export function ProviderIcon({ provider, className }: { provider: ProviderValue; className?: string }) {
    if (provider === 'github') return <Github className={className} />;
    if (provider === 'gitlab') return <Gitlab className={className} />;
    if (provider === 'bitbucket') return <GitBranch className={className} />;

    return <KeyRound className={className} />;
}
