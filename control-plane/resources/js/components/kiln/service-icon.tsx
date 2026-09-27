import { cn } from '@/lib/utils';
import { Box, Code2, Database, FileCode2, Globe, HardDrive, Server, type LucideIcon } from 'lucide-react';
import {
    siBun,
    siCaddy,
    siDeno,
    siDocker,
    siLaravel,
    siMariadb,
    siMysql,
    siNextdotjs,
    siNodedotjs,
    siNuxt,
    siPhp,
    siPostgresql,
    siRedis,
    siStatamic,
    siSymfony,
    siWordpress,
    type SimpleIcon,
} from 'simple-icons';

/** Brand logos (simple-icons) keyed by the ServiceIcon keys the canvas read model uses (§9). */
const BRANDS: Record<string, SimpleIcon> = {
    laravel: siLaravel,
    symfony: siSymfony,
    statamic: siStatamic,
    wordpress: siWordpress,
    php: siPhp,
    frankenphp: siPhp,
    'php-fpm': siPhp,
    next: siNextdotjs,
    nextjs: siNextdotjs,
    nextdotjs: siNextdotjs,
    nuxt: siNuxt,
    node: siNodedotjs,
    nodejs: siNodedotjs,
    nodedotjs: siNodedotjs,
    bun: siBun,
    deno: siDeno,
    docker: siDocker,
    compose: siDocker,
    postgresql: siPostgresql,
    postgres: siPostgresql,
    pgsql: siPostgresql,
    mysql: siMysql,
    mariadb: siMariadb,
    redis: siRedis,
    valkey: siRedis,
    caddy: siCaddy,
};

/** Generic fallbacks for kinds without a brand logo. */
const GENERIC: Record<string, LucideIcon> = {
    site: Globe,
    static: FileCode2,
    database: Database,
    storage: HardDrive,
    server: Server,
    service: Box,
    custom: Code2,
};

// Logos whose brand color is (near) black/white read poorly on one theme: draw them in the text color instead.
const MONOCHROME = new Set(['nextdotjs', 'bun', 'deno', 'symfony']);

export function hasServiceIcon(name: string | null | undefined): boolean {
    return Boolean(name && (BRANDS[name.toLowerCase()] || GENERIC[name.toLowerCase()]));
}

export interface ServiceIconProps {
    /** 'laravel' | 'next' | 'bun' | 'postgresql' | … or a generic kind ('site', 'database', 'server'). */
    name: string | null | undefined;
    size?: number;
    /** Render in currentColor instead of the brand color. */
    mono?: boolean;
    /** Accessible label; decorative when omitted. */
    title?: string;
    className?: string;
}

export function ServiceIcon({ name, size = 16, mono = false, title, className }: ServiceIconProps) {
    const key = (name ?? '').toLowerCase();
    const brand = BRANDS[key];
    const a11y = title ? { role: 'img' as const, 'aria-label': title } : { 'aria-hidden': true as const };

    if (brand) {
        const monochrome = mono || MONOCHROME.has(brand.slug);

        return (
            <svg
                viewBox="0 0 24 24"
                width={size}
                height={size}
                className={cn('shrink-0', monochrome && 'text-fg', className)}
                // Brand colors are data (the logo's identity), not UI tokens.
                fill={monochrome ? 'currentColor' : `#${brand.hex}`}
                {...a11y}
            >
                {title && <title>{title}</title>}
                <path d={brand.path} />
            </svg>
        );
    }

    const Generic = GENERIC[key] ?? Box;

    return <Generic width={size} height={size} className={cn('text-fg-muted shrink-0', className)} {...a11y} />;
}
