import { SERVICE_CARD } from '@/components/falak';
import { type CanvasGroup, type CanvasService, type ComposeChild } from '@/types';

/**
 * Canvas layout math (docs/UI_DESIGN.md §4.3), pure so it can be reasoned about and tested:
 * - a card is 240 × 112, plus a 30px strip when it has persistent storage;
 * - a group frame is derived from its members' bounding box (padding + a header row), so it always wraps them;
 * - members of user groups are stored relative to the group's anchor, compose services relative to their site card.
 */
export const GROUP = { padding: 20, header: 46, collapsedWidth: 280, collapsedHeight: 96 } as const;

export interface Point {
    x: number;
    y: number;
}

export interface Box extends Point {
    width: number;
    height: number;
}

export function cardHeight(item: { volumes?: unknown[] | null }): number {
    return SERVICE_CARD.height + ((item.volumes?.length ?? 0) > 0 ? SERVICE_CARD.strip : 0);
}

/** Frame around absolutely positioned members (or a placeholder at `fallback` when empty). */
export function frame(members: (Point & { height: number })[], fallback: Point, collapsed = false): Box {
    if (members.length === 0) {
        return { x: fallback.x, y: fallback.y, width: GROUP.collapsedWidth, height: GROUP.collapsedHeight };
    }
    const minX = Math.min(...members.map((member) => member.x));
    const minY = Math.min(...members.map((member) => member.y));
    const maxX = Math.max(...members.map((member) => member.x + SERVICE_CARD.width));
    const maxY = Math.max(...members.map((member) => member.y + member.height));
    const x = minX - GROUP.padding;
    const y = minY - GROUP.header;

    if (collapsed) return { x, y, width: GROUP.collapsedWidth, height: GROUP.collapsedHeight };

    return { x, y, width: maxX - minX + GROUP.padding * 2, height: maxY - minY + GROUP.header + GROUP.padding };
}

/** Absolute position of a service card (grouped cards are stored relative to their group anchor). */
export function absoluteOf(service: CanvasService, groups: Map<string, CanvasGroup>): Point {
    const group = service.group_id ? groups.get(service.group_id) : undefined;

    return group ? { x: group.position.x + service.position.x, y: group.position.y + service.position.y } : service.position;
}

export function composeMembers(service: CanvasService): (Point & { height: number; child: ComposeChild })[] {
    return (service.compose?.services ?? []).map((child) => ({
        x: service.position.x + child.position.x,
        y: service.position.y + child.position.y,
        height: cardHeight(child),
        child,
    }));
}

export function contains(box: Box, point: Point): boolean {
    return point.x >= box.x && point.x <= box.x + box.width && point.y >= box.y && point.y <= box.y + box.height;
}

/** Default two-column layout of compose services, relative to the site card (matches ComposeGroup.php). */
export function composeGrid(index: number): Point {
    return { x: (index % 2) * 300, y: Math.floor(index / 2) * 180 };
}
