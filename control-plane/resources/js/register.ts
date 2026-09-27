import { setAppearance } from '@/hooks/use-appearance';
import { registerCommands } from '@/lib/registry';
import { FolderKanban, Monitor, Moon, Sun } from 'lucide-react';

// Core shell commands. Modules register their own in modules/<Module>/resources/js/register.ts.
registerCommands(
    {
        id: 'core.navigation',
        commands: ({ props }) => [
            {
                id: 'nav.projects',
                title: 'Projects',
                group: 'Navigation',
                icon: FolderKanban,
                // The Projects module shares `kiln`; until it is installed the dashboard is home.
                href: props.kiln ? '/projects' : '/dashboard',
                shortcut: 'G P',
                keywords: ['home', 'dashboard', 'canvas'],
            },
        ],
    },
    {
        id: 'core.preferences',
        commands: () => [
            {
                id: 'theme.dark',
                title: 'Theme: Dark',
                group: 'Preferences',
                icon: Moon,
                perform: () => setAppearance('dark'),
                keywords: ['appearance'],
            },
            {
                id: 'theme.light',
                title: 'Theme: Light',
                group: 'Preferences',
                icon: Sun,
                perform: () => setAppearance('light'),
                keywords: ['appearance'],
            },
            {
                id: 'theme.system',
                title: 'Theme: System',
                group: 'Preferences',
                icon: Monitor,
                perform: () => setAppearance('system'),
                keywords: ['appearance', 'auto'],
            },
        ],
    },
);
