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
import { memo, useEffect, useMemo, type MouseEvent as ReactMouseEvent } from 'react';

type ServiceNode = Node<{ service: CanvasService; selected: boolean }, 'service'>;

const ServiceNodeView = memo(function ServiceNodeView({ data }: NodeProps<ServiceNode>) {
    return (
        <>
            <Handle type="target" position={Position.Left} className="kiln-handle" isConnectable={false} />
            <ServiceCard service={data.service} selected={data.selected} className="cursor-pointer" />
            <Handle type="source" position={Position.Right} className="kiln-handle" isConnectable={false} />
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

    const flowEdges = useMemo<Edge[]>(
        () =>
            edges.map((edge) => ({
                id: `${edge.from}>${edge.to}`,
                source: edge.from,
                target: edge.to,
                className: 'kiln-edge',
                focusable: false,
                selectable: false,
            })),
        [edges],
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
            nodes={nodes}
            edges={flowEdges}
            nodeTypes={nodeTypes}
            onNodesChange={onNodesChange}
            onNodeClick={(_, node) => onOpen(node.data.service)}
            onNodeDragStop={(_, node) =>
                onMove(node.data.service, { x: Math.round(node.position.x), y: Math.round(node.position.y) })
            }
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
            <Background variant={BackgroundVariant.Dots} gap={20} size={1} color="var(--grid)" bgColor="var(--bg-canvas)" />
        </ReactFlow>
    );
}
