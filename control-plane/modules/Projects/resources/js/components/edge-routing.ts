/**
 * Orthogonal routing for the canvas's derived edges (§4): paths leave and enter cards on facing sides and run
 * through the gaps between cards, never behind one. Pure geometry so it can be unit-tested.
 */
export interface Rect {
    x: number;
    y: number;
    width: number;
    height: number;
}

export interface Point {
    x: number;
    y: number;
}

type Side = 'left' | 'right' | 'top' | 'bottom';

const CLEARANCE = 10; // px kept free around other cards
const DETOUR = 36; // distance of a detour lane from the cards it passes

function anchor(rect: Rect, side: Side): Point {
    switch (side) {
        case 'left':
            return { x: rect.x, y: rect.y + rect.height / 2 };
        case 'right':
            return { x: rect.x + rect.width, y: rect.y + rect.height / 2 };
        case 'top':
            return { x: rect.x + rect.width / 2, y: rect.y };
        default:
            return { x: rect.x + rect.width / 2, y: rect.y + rect.height };
    }
}

/** Does the axis-aligned segment a→b cross the rect (inflated by the clearance)? */
function hits(a: Point, b: Point, rect: Rect): boolean {
    const left = rect.x - CLEARANCE;
    const right = rect.x + rect.width + CLEARANCE;
    const top = rect.y - CLEARANCE;
    const bottom = rect.y + rect.height + CLEARANCE;

    if (a.y === b.y) {
        const [x1, x2] = a.x < b.x ? [a.x, b.x] : [b.x, a.x];

        return a.y > top && a.y < bottom && x2 > left && x1 < right;
    }
    const [y1, y2] = a.y < b.y ? [a.y, b.y] : [b.y, a.y];

    return a.x > left && a.x < right && y2 > top && y1 < bottom;
}

function blocked(points: Point[], obstacles: Rect[]): boolean {
    for (let index = 1; index < points.length; index++) {
        if (obstacles.some((rect) => hits(points[index - 1], points[index], rect))) return true;
    }

    return false;
}

function length(points: Point[]): number {
    let total = 0;
    for (let index = 1; index < points.length; index++) {
        total += Math.abs(points[index].x - points[index - 1].x) + Math.abs(points[index].y - points[index - 1].y);
    }

    return total;
}

/** Drop zero-length and collinear points. */
function simplify(points: Point[]): Point[] {
    const out: Point[] = [];
    for (const point of points) {
        const last = out[out.length - 1];
        if (last && last.x === point.x && last.y === point.y) continue;
        const before = out[out.length - 2];
        if (before && last && ((before.x === last.x && last.x === point.x) || (before.y === last.y && last.y === point.y))) out.pop();
        out.push(point);
    }

    return out;
}

/** Candidate routes from `from` to `to`, ordered by preference. */
function candidates(from: Rect, to: Rect, obstacles: Rect[]): Point[][] {
    const routes: Point[][] = [];
    const fromRight = from.x + from.width;
    const toRight = to.x + to.width;
    const fromBottom = from.y + from.height;
    const toBottom = to.y + to.height;

    // Side by side: leave on the facing side, turn once in the gap between the two cards.
    if (to.x >= fromRight || toRight <= from.x) {
        const rightwards = to.x >= fromRight;
        const s = anchor(from, rightwards ? 'right' : 'left');
        const t = anchor(to, rightwards ? 'left' : 'right');
        const gapStart = rightwards ? fromRight : toRight;
        const gapEnd = rightwards ? to.x : from.x;
        // Try the middle of the gap first, then lanes next to each card (other cards may sit in between).
        const lanes = [
            (gapStart + gapEnd) / 2,
            gapStart + DETOUR / 2,
            gapEnd - DETOUR / 2,
            ...obstacles.flatMap((rect) => [rect.x - DETOUR / 2, rect.x + rect.width + DETOUR / 2]),
        ]
            .filter((x) => x > gapStart && x < gapEnd)
            .map(Math.round);
        for (const x of [...new Set(lanes)]) routes.push([s, { x, y: s.y }, { x, y: t.y }, t]);
    }

    // Stacked: bottom → top (or top → bottom) with one turn in the vertical gap.
    if (to.y >= fromBottom || toBottom <= from.y) {
        const downwards = to.y >= fromBottom;
        const s = anchor(from, downwards ? 'bottom' : 'top');
        const t = anchor(to, downwards ? 'top' : 'bottom');
        const y = Math.round(downwards ? (fromBottom + to.y) / 2 : (toBottom + from.y) / 2);
        routes.push([s, { x: s.x, y }, { x: t.x, y }, t]);
    }

    // Detours below / above everything between the two cards.
    const minX = Math.min(from.x, to.x);
    const maxX = Math.max(fromRight, toRight);
    const between = obstacles.filter((rect) => rect.x + rect.width > minX - CLEARANCE && rect.x < maxX + CLEARANCE);
    const below = Math.max(fromBottom, toBottom, ...between.map((rect) => rect.y + rect.height)) + DETOUR;
    const above = Math.min(from.y, to.y, ...between.map((rect) => rect.y)) - DETOUR;
    const sb = anchor(from, 'bottom');
    const tb = anchor(to, 'bottom');
    const st = anchor(from, 'top');
    const tt = anchor(to, 'top');
    routes.push([sb, { x: sb.x, y: below }, { x: tb.x, y: below }, tb]);
    routes.push([st, { x: st.x, y: above }, { x: tt.x, y: above }, tt]);

    return routes.map(simplify);
}

/** The route from one card to another around every other card (shortest clear candidate, else the lane below). */
export function routeEdge(from: Rect, to: Rect, obstacles: Rect[]): Point[] {
    const routes = candidates(from, to, obstacles);
    const clear = routes.filter((route) => !blocked(route, obstacles));
    const preferred = clear.length > 0 ? clear : routes.slice(-2);

    return preferred.reduce((best, route) => (length(route) < length(best) - 1 ? route : best), preferred[0]);
}

/** SVG path through the points with rounded corners. */
export function roundedPath(points: Point[], radius = 10): string {
    if (points.length < 2) return '';
    let path = `M ${points[0].x} ${points[0].y}`;
    for (let index = 1; index < points.length - 1; index++) {
        const prev = points[index - 1];
        const corner = points[index];
        const next = points[index + 1];
        const r = Math.min(radius, Math.hypot(corner.x - prev.x, corner.y - prev.y) / 2, Math.hypot(next.x - corner.x, next.y - corner.y) / 2);
        const inX = corner.x - Math.sign(corner.x - prev.x) * r;
        const inY = corner.y - Math.sign(corner.y - prev.y) * r;
        const outX = corner.x + Math.sign(next.x - corner.x) * r;
        const outY = corner.y + Math.sign(next.y - corner.y) * r;
        path += ` L ${inX} ${inY} Q ${corner.x} ${corner.y} ${outX} ${outY}`;
    }
    const last = points[points.length - 1];

    return `${path} L ${last.x} ${last.y}`;
}
