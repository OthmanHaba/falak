import { ServiceCard } from '@/components/kiln';
import { type CanvasService } from '@/types';
import {
    Background,
    BackgroundVariant,
    Handle,
    Position,
    ReactFlow,
    useNodesState,
    useReactFlow,
    type Edge,
    type Node,
    type NodeProps,
} from '@xyflow/react';
import '@xyflow/react/dist/base.css';
import { memo, useEffect, useMemo, useState, type MouseEvent as ReactMouseEvent } from 'react';

const SIDES = [
    ['l', Position.Left],
    ['r', Position.Right],
    ['t', Position.Top],
    ['b', Position.Bottom],
] as const;

const CARD = { width: 240, height: 96 };

/** Connect the facing sides of two cards so derived edges never cut across them. */
function sides(from: { x: number; y: number }, to: { x: number; y: number }): { source: string; target: string } {
    if (to.x >= from.x + CARD.width) return { source: 's-r', target: 't-l' };
    if (to.x + CARD.width <= from.x) return { source: 's-l', target: 't-r' };

    return to.y >= from.y ? { source: 's-b', target: 't-t' } : { source: 's-t', target: 't-b' };
}

/** Follow the app theme (the `dark` class on <html>), so xyflow's own `light`/`dark` class never fights it. */
function useDocumentTheme(): 'dark' | 'light' {
    const read = () => (document.documentElement.classList.contains('dark') ? 'dark' : 'light');
    const [theme, setTheme] = useState<'dark' | 'light'>(read);

    useEffect(() => {
        const observer = new MutationObserver(() => setTheme(read()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, []);

    return theme;
}

type ServiceNode = Node<{ service: CanvasService; selected: boolean }, 'service'>;

const ServiceNodeView = memo(function ServiceNodeView({ data }: NodeProps<ServiceNode>) {
    return (
        <>
            {SIDES.map(([id, position]) => (
                <Handle key={`t-${id}`} id={`t-${id}`} type="target" position={position} className="kiln-handle" isConnectable={false} />
            ))}
            <ServiceCard service={data.service} selected={data.selected} className="cursor-pointer" />
            {SIDES.map(([id, position]) => (
                <Handle key={`s-${id}`} id={`s-${id}`} type="source" position={position} className="kiln-handle" isConnectable={false} />
            ))}
        </>
    );
});

const nodeTypes = { service: ServiceNodeView };

export interface CanvasBoardProps {
    services: CanvasService[];
    edges: { from: string; to: string }[];
    selectedId: string | null;
    draggable: boolean;
    onOpen: (service: CanvasService) => void;
    onMove: (service: CanvasService, position: { x: number; y: number }) => void;
    /** Right-click on empty canvas, with the flow coordinates of the click. */
    onContextMenu?: (flow: { x: number; y: number }, screen: { x: number; y: number }) => void;
}

function toNode(service: CanvasService, selectedId: string | null, draggable: boolean): ServiceNode {
    return {
        id: service.id,
        type: 'service',
        position: service.position,
        data: { service, selected: service.id === selectedId },
        draggable,
        selectable: false,
        ariaLabel: `${service.name}: ${service.status_label}`,
    };
}

/**
 * The project canvas (§4): dotted grid, drag to pan (space+drag too), ⌘/Ctrl + scroll or +/− to zoom, service
 * cards with derived dashed edges. Card positions persist per environment (onMove).
 */
export function CanvasBoard({ services, edges, selectedId, draggable, onOpen, onMove, onContextMenu }: CanvasBoardProps) {
    const [nodes, setNodes, onNodesChange] = useNodesState<ServiceNode>([]);
    const flow = useReactFlow();

    // Server data wins, except for the card being dragged right now.
    useEffect(() => {
        setNodes((current) => {
            const dragging = new Map(current.filter((node) => node.dragging).map((node) => [node.id, node]));

            return services.map((service) => {
                const node = toNode(service, selectedId, draggable);
                const active = dragging.get(service.id);

                return active ? { ...node, position: active.position, dragging: true } : node;
            });
        });
    }, [services, selectedId, draggable, setNodes]);

    const positions = useMemo(() => new Map(nodes.map((node) => [node.id, node.position])), [nodes]);
    const colorMode = useDocumentTheme();

    const flowEdges = useMemo<Edge[]>(
        () =>
            edges.map((edge) => ({
                id: `${edge.from}>${edge.to}`,
                source: edge.from,
                target: edge.to,
                ...(() => {
                    const from = positions.get(edge.from);
                    const to = positions.get(edge.to);
                    const handles = from && to ? sides(from, to) : { source: 's-r', target: 't-l' };

                    return { sourceHandle: handles.source, targetHandle: handles.target };
                })(),
                className: 'kiln-edge',
                focusable: false,
                selectable: false,
            })),
        [edges, positions],
    );

    // Keyboard zoom (+ / −) and fit (shift+1), outside text fields and dialogs.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
            if (document.querySelector('[role="dialog"]')) return;
            if (event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.key === '+' || event.key === '=') flow.zoomIn({ duration: 150 });
            else if (event.key === '-' || event.key === '_') flow.zoomOut({ duration: 150 });
            else if (event.key === '!') flow.fitView({ duration: 220, padding: 0.3, maxZoom: 1 });
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [flow]);

    return (
        <ReactFlow<ServiceNode>
            className="kiln-canvas"
            colorMode={colorMode}
            nodes={nodes}
            edges={flowEdges}
            nodeTypes={nodeTypes}
            onNodesChange={onNodesChange}
            onNodeClick={(_, node) => onOpen(node.data.service)}
            onNodeDragStop={(_, node) => onMove(node.data.service, { x: Math.round(node.position.x), y: Math.round(node.position.y) })}
            onPaneContextMenu={(event: ReactMouseEvent | MouseEvent) => {
                if (!onContextMenu) return;
                event.preventDefault();
                onContextMenu(flow.screenToFlowPosition({ x: event.clientX, y: event.clientY }), { x: event.clientX, y: event.clientY });
            }}
            nodesConnectable={false}
            elementsSelectable={false}
            snapToGrid
            snapGrid={[20, 20]}
            zoomOnScroll={false}
            panOnScroll
            zoomActivationKeyCode={['Meta', 'Control']}
            minZoom={0.3}
            maxZoom={1.75}
            fitView
            fitViewOptions={{ padding: 0.3, maxZoom: 1 }}
            proOptions={{ hideAttribution: true }}
            deleteKeyCode={null}
        >
            <Background variant={BackgroundVariant.Dots} gap={20} size={1} />
        </ReactFlow>
    );
}
