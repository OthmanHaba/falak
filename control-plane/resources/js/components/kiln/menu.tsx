import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { MoreHorizontal } from 'lucide-react';
import { forwardRef, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { IconButton } from './button';
import { Kbd } from './kbd';

export const MenuRoot = DropdownMenu.Root;
export const MenuTrigger = DropdownMenu.Trigger;
export const MenuGroup = DropdownMenu.Group;

export const MenuContent = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<typeof DropdownMenu.Content>>(
    ({ className, sideOffset = 6, align = 'end', ...props }, ref) => (
        <DropdownMenu.Portal>
            <DropdownMenu.Content
                ref={ref}
                sideOffset={sideOffset}
                align={align}
                collisionPadding={8}
                className={cn(
                    'animate-fade-in border-border bg-surface-1 shadow-panel z-50 max-h-(--radix-dropdown-menu-content-available-height) min-w-48 overflow-y-auto rounded-lg border p-1',
                    className,
                )}
                {...props}
            />
        </DropdownMenu.Portal>
    ),
);
MenuContent.displayName = 'MenuContent';

const itemClasses =
    'relative flex w-full cursor-default items-center gap-2 rounded-md px-2 py-1.5 text-sm text-fg outline-none select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[highlighted]:bg-surface-2 [&_svg]:size-4 [&_svg]:shrink-0 [&_svg]:text-fg-muted';

export interface MenuItemProps extends ComponentPropsWithoutRef<typeof DropdownMenu.Item> {
    icon?: ReactNode;
    shortcut?: string;
    danger?: boolean;
}

export const MenuItem = forwardRef<HTMLDivElement, MenuItemProps>(({ icon, shortcut, danger, className, children, ...props }, ref) => (
    <DropdownMenu.Item ref={ref} className={cn(itemClasses, danger && 'text-danger [&_svg]:text-danger', className)} {...props}>
        {icon}
        <span className="min-w-0 flex-1 truncate">{children}</span>
        {shortcut && <Kbd className="ml-auto">{shortcut}</Kbd>}
    </DropdownMenu.Item>
));
MenuItem.displayName = 'MenuItem';

/** Menu entry that navigates with Inertia. */
export function MenuLink({
    href,
    icon,
    children,
    method,
    external,
}: {
    href: string;
    icon?: ReactNode;
    children: ReactNode;
    method?: 'post' | 'delete';
    external?: boolean;
}) {
    return (
        <DropdownMenu.Item asChild className={itemClasses}>
            {external ? (
                <a href={href} target="_blank" rel="noreferrer">
                    {icon}
                    <span className="min-w-0 flex-1 truncate">{children}</span>
                </a>
            ) : (
                <Link href={href} method={method} as={method ? 'button' : 'a'}>
                    {icon}
                    <span className="min-w-0 flex-1 truncate">{children}</span>
                </Link>
            )}
        </DropdownMenu.Item>
    );
}

export function MenuLabel({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <DropdownMenu.Label className={cn('text-2xs text-fg-faint px-2 pt-1.5 pb-1 font-medium tracking-wide uppercase', className)}>
            {children}
        </DropdownMenu.Label>
    );
}

export function MenuSeparator() {
    return <DropdownMenu.Separator className="bg-border -mx-1 my-1 h-px" />;
}

export type MenuAction =
    | {
          type?: 'item';
          label: string;
          icon?: ReactNode;
          onSelect?: () => void;
          href?: string;
          shortcut?: string;
          danger?: boolean;
          disabled?: boolean;
      }
    | { type: 'separator' }
    | { type: 'label'; label: string };

export function MenuActions({ actions }: { actions: MenuAction[] }) {
    return (
        <>
            {actions.map((action, index) => {
                if (action.type === 'separator') return <MenuSeparator key={index} />;
                if (action.type === 'label') return <MenuLabel key={index}>{action.label}</MenuLabel>;
                if (action.href) {
                    return (
                        <MenuLink key={index} href={action.href} icon={action.icon}>
                            {action.label}
                        </MenuLink>
                    );
                }

                return (
                    <MenuItem
                        key={index}
                        icon={action.icon}
                        shortcut={action.shortcut}
                        danger={action.danger}
                        disabled={action.disabled}
                        onSelect={action.onSelect}
                    >
                        {action.label}
                    </MenuItem>
                );
            })}
        </>
    );
}

/** The `⋯` overflow menu. */
export function Menu({
    actions,
    label = 'More actions',
    trigger,
    align = 'end',
}: {
    actions: MenuAction[];
    label?: string;
    trigger?: ReactNode;
    align?: 'start' | 'end';
}) {
    return (
        <MenuRoot>
            <MenuTrigger asChild>{trigger ?? <IconButton label={label} tooltip={false} size="sm" icon={<MoreHorizontal />} />}</MenuTrigger>
            <MenuContent align={align}>
                <MenuActions actions={actions} />
            </MenuContent>
        </MenuRoot>
    );
}
