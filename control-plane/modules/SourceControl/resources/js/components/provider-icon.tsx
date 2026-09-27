import { IntegrationIcon } from '@/components/kiln/integration-icon';
import { type ProviderValue } from '../types';

export function ProviderIcon({ provider, className, size = 16 }: { provider: ProviderValue; className?: string; size?: number }) {
    return <IntegrationIcon name={provider === 'custom' ? 'git' : provider} size={size} className={className} />;
}
