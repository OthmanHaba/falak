import { registerCommands } from '@/lib/registry';
import { LayoutGrid, Palette } from 'lucide-react';

// Core shell commands. Modules register their own in modules/<Module>/resources/js/register.ts.
registerCommands({
    id: 'core.navigation',
    commands: () => [
        { id: 'nav.dashboard', title: 'Dashboard', group: 'Navigation', icon: LayoutGrid, href: '/dashboard' },
        { id: 'nav.appearance', title: 'Appearance', group: 'Settings', icon: Palette, href: '/settings/appearance', keywords: ['theme', 'dark'] },
    ],
});
