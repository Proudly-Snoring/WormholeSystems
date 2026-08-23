import type { EdgeGeometry, EdgeInput, Rect, Vec2 } from '../types';

/** Spacing between connections that leave the same node edge, in base units. */
export const PARALLEL_SPACING = 14;
/** Spacing between the stacked runs of detours sharing a column, in base units. */
export const BEND_SPACING = 16;
/** Clear space kept between a vertical run and the column it routes beside, in base units. */
export const LANE_MARGIN = 40;

export type Endpoints = { from: Vec2; to: Vec2; fromNormal: Vec2; toNormal: Vec2 };

/**
 * Connects the centre of each box's facing edge, leaving perpendicular to it.
 *
 * Prefer the left/right edges whenever the boxes are separated horizontally — then the
 * elbow drops its long vertical run through the clear lane between the columns
 * (down, then right into the node) instead of routing down-over-down with the second
 * vertical run cutting straight through the column of stacked siblings. Top/bottom
 * edges are only used when the boxes share a column, so there is no horizontal lane.
 */
export function edgeCenterConnection(source: Rect, target: Rect): Endpoints {
    const dx = target.centerX - source.centerX;
    const dy = target.centerY - source.centerY;

    const separatedX = target.minX > source.maxX || source.minX > target.maxX;
    const separatedY = target.minY > source.maxY || source.minY > target.maxY;
    const useHorizontal = separatedX || (!separatedY && Math.abs(dx) >= Math.abs(dy));

    if (useHorizontal) {
        const rightward = dx >= 0;
        return {
            from: { x: rightward ? source.maxX : source.minX, y: source.centerY },
            to: { x: rightward ? target.minX : target.maxX, y: target.centerY },
            fromNormal: { x: rightward ? 1 : -1, y: 0 },
            toNormal: { x: rightward ? -1 : 1, y: 0 },
        };
    }

    const downward = dy >= 0;
    return {
        from: { x: source.centerX, y: downward ? source.maxY : source.minY },
        to: { x: target.centerX, y: downward ? target.minY : target.maxY },
        fromNormal: { x: 0, y: downward ? 1 : -1 },
        toNormal: { x: 0, y: downward ? -1 : 1 },
    };
}

/** Both ends out the same side, for two nodes in one column with something between them. */
function detourConnection(source: Rect, target: Rect): Endpoints {
    return {
        from: { x: source.maxX, y: source.centerY },
        to: { x: target.maxX, y: target.centerY },
        fromNormal: { x: 1, y: 0 },
        toNormal: { x: 1, y: 0 },
    };
}

/** Whether a node sits between these two in their shared column, which a run would cross. */
function blockedInColumn(source: Rect, target: Rect, column: Rect[]): boolean {
    const top = Math.min(source.centerY, target.centerY);
    const bottom = Math.max(source.centerY, target.centerY);
    return column.some((other) => other !== source && other !== target && other.minY < bottom && other.maxY > top);
}

type Column = { left: number; right: number };

/**
 * A vertical run belongs in the lanes between columns, never inside one: crossing another
 * edge is readable, disappearing behind a node is not. Only columns the run actually
 * passes between count, and the shifted lane stays inside them, so an edge never runs past
 * the node it is heading for and doubles back.
 */
function intoLane(x: number, columns: Column[], from: number, to: number): number {
    const near = Math.min(from, to);
    const far = Math.max(from, to);
    for (const column of columns) {
        if (column.right <= near || column.left >= far) continue;
        if (x > column.left - LANE_MARGIN / 2 && x < column.right + LANE_MARGIN) {
            return clamp(column.right + LANE_MARGIN, near, far);
        }
    }
    return x;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

/**
 * Positions for the lanes of one corridor honouring `precedes` (lane → lanes that must
 * sit farther out), keeping the original order where the constraints leave a choice.
 * A cycle would mean tails that cannot all be kept apart; the tie is broken in favour
 * of the earlier lane and the rest still honoured.
 */
function laneOrder(precedes: ReadonlySet<number>[]): number[] {
    const indegree = precedes.map(() => 0);
    for (const targets of precedes) {
        for (const target of targets) {
            indegree[target]++;
        }
    }
    const position = precedes.map(() => 0);
    const placed = new Set<number>();
    for (let slot = 0; slot < precedes.length; slot++) {
        let pick = -1;
        for (let lane = 0; lane < precedes.length; lane++) {
            if (placed.has(lane)) continue;
            if (indegree[lane] === 0) {
                pick = lane;
                break;
            }
            if (pick === -1) pick = lane;
        }
        placed.add(pick);
        position[pick] = slot;
        for (const target of precedes[pick]) {
            if (!placed.has(target)) indegree[target]--;
        }
    }
    return position;
}

type Port = { endpoint: Vec2; normal: Vec2; box: Rect; sortKey: number };

/**
 * Fans out connections that share a node edge so parallel lines don't overlap:
 * each shared edge spreads its endpoints along itself, ordered by the other end's
 * position so the lines stay untangled.
 */
function spreadSharedEdges(ports: Port[]): void {
    if (ports.length < 2) return;
    const alongY = ports[0].normal.x !== 0;
    const box = ports[0].box;
    const extent = alongY ? box.maxY - box.minY : box.maxX - box.minX;
    const spacing = Math.min(PARALLEL_SPACING, (extent * 0.7) / (ports.length - 1));
    ports.sort((a, b) => a.sortKey - b.sortKey);
    ports.forEach((port, i) => {
        const offset = (i - (ports.length - 1) / 2) * spacing;
        if (alongY) {
            port.endpoint.y += offset;
        } else {
            port.endpoint.x += offset;
        }
    });
}

type RoutedEdge = Extract<EdgeGeometry, { kind: 'elbow' }> & {
    sourceBox: Rect;
    targetBox: Rect;
    /** Both ends leave the same side, to get around the nodes between them. */
    detour: boolean;
    /** Perpendicular distance and signed offset of the far end, for ordering the fan. */
    distance: number;
    signed: number;
};

/**
 * Tree-layout routing, a global pass over all edges: connects facing box edges (or sends
 * both ends out the same side when the straight run would vanish behind a node in the
 * shared column), fans out endpoints that share a node edge, packs the perpendicular
 * runs crossing one corridor onto the fewest lanes that keep them apart, and steers
 * vertical runs out of the columns they only pass. `rects` should cover every measured
 * node — routing steers around whatever is actually in the way, connected or not.
 * Edges whose nodes are not both measured fall back to a straight curve between
 * their anchors (edges missing an anchor entirely are dropped).
 */
export function computeTreeEdgeGeometries(
    edges: EdgeInput[],
    rects: ReadonlyMap<number, Rect>,
    anchors: ReadonlyMap<number, Vec2>,
): Map<number, EdgeGeometry> {
    const geometries = new Map<number, EdgeGeometry>();
    const routed: RoutedEdge[] = [];

    // One rect per node, shared below so the column members can be compared by identity.
    const byColumn = new Map<number, Rect[]>();
    for (const rect of rects.values()) {
        (byColumn.get(rect.minX) ?? byColumn.set(rect.minX, []).get(rect.minX)!).push(rect);
    }
    const columns: Column[] = [...byColumn.entries()]
        .map(([left, members]) => ({ left, right: Math.max(...members.map((member) => member.maxX)) }))
        .sort((a, b) => a.left - b.left);
    const columnRight = (left: number): number => columns.find((column) => column.left === left)?.right ?? left;

    for (const edge of edges) {
        const sourceBox = rects.get(edge.sourceId);
        const targetBox = rects.get(edge.targetId);
        if (!sourceBox || !targetBox) {
            const from = anchors.get(edge.sourceId);
            const to = anchors.get(edge.targetId);
            if (from && to) {
                geometries.set(edge.id, { id: edge.id, kind: 'curve', from, to });
            }
            continue;
        }
        const detour = sourceBox.minX === targetBox.minX && blockedInColumn(sourceBox, targetBox, byColumn.get(sourceBox.minX) ?? []);
        const ends = detour ? detourConnection(sourceBox, targetBox) : edgeCenterConnection(sourceBox, targetBox);
        const item: RoutedEdge = {
            id: edge.id,
            kind: 'elbow',
            from: { ...ends.from },
            to: { ...ends.to },
            fromNormal: ends.fromNormal,
            toNormal: ends.toNormal,
            bend: null,
            sourceBox,
            targetBox,
            detour,
            distance: 0,
            signed: 0,
        };
        routed.push(item);
        geometries.set(edge.id, item);
    }

    // Fan out the endpoints that share a node edge so parallel lines don't overlap.
    const sharedEdges = new Map<string, Port[]>();
    const register = (endpoint: Vec2, normal: Vec2, box: Rect, other: Rect): void => {
        const port: Port = { endpoint, normal, box, sortKey: normal.x !== 0 ? other.centerY : other.centerX };
        const key = `${box.centerX},${box.centerY}|${normal.x},${normal.y}`;
        (sharedEdges.get(key) ?? sharedEdges.set(key, []).get(key)!).push(port);
    };
    for (const item of routed) {
        register(item.from, item.fromNormal, item.sourceBox, item.targetBox);
        register(item.to, item.toNormal, item.targetBox, item.sourceBox);
    }
    const spreadCount = new Map<Vec2, number>();
    for (const ports of sharedEdges.values()) {
        spreadSharedEdges(ports);
        for (const port of ports) {
            spreadCount.set(port.endpoint, ports.length);
        }
    }

    // Two nodes sitting level would be joined by a straight line, except that spreading the
    // ports on the busier one lifts its end off the other's centre line and leaves a jog of
    // a few pixels. Where the quiet end is this edge's alone, it follows the busy one
    // instead: entering off-centre reads better than a kink that means nothing.
    for (const item of routed) {
        if (item.detour) continue;
        const horizontal = item.fromNormal.x !== 0;
        const level = horizontal
            ? Math.abs(item.sourceBox.centerY - item.targetBox.centerY) < 0.5
            : Math.abs(item.sourceBox.centerX - item.targetBox.centerX) < 0.5;
        if (!level) continue;
        const fromShared = spreadCount.get(item.from) ?? 1;
        const toShared = spreadCount.get(item.to) ?? 1;
        if (fromShared === toShared) continue;
        const [follow, lead] = fromShared < toShared ? [item.from, item.to] : [item.to, item.from];
        if (horizontal) {
            follow.y = lead.y;
        } else {
            follow.x = lead.x;
        }
    }

    // Grouped by the corridor the run crosses, not by the node it leaves: two runs at the
    // same offset in the same corridor are the same line, whichever nodes they belong to,
    // so they all have to be packed together or they lie on top of each other.
    const fans = new Map<string, RoutedEdge[]>();
    for (const item of routed) {
        if (item.detour) continue;
        const horizontal = item.fromNormal.x !== 0;
        const sourceFirst = horizontal ? item.sourceBox.centerX <= item.targetBox.centerX : item.sourceBox.centerY <= item.targetBox.centerY;
        const primary = sourceFirst ? item.sourceBox : item.targetBox;
        const other = sourceFirst ? item.targetBox : item.sourceBox;
        const along = horizontal ? other.centerY - primary.centerY : other.centerX - primary.centerX;
        item.distance = Math.abs(along);
        item.signed = along;
        const across = horizontal ? [item.from.x, item.to.x] : [item.from.y, item.to.y];
        const key = `${horizontal ? 'h' : 'v'}|${Math.min(...across)},${Math.max(...across)}`;
        (fans.get(key) ?? fans.set(key, []).get(key)!).push(item);
    }
    // Runs crossing one corridor only need separate lines where they would overlap and hide
    // each other: a run up and a run down can sit on the same line and still be told apart,
    // because each keeps its own colour. So pack them onto as few lines as possible, then
    // space those across the corridor.
    for (const group of fans.values()) {
        if (group.length < 2) continue;
        const horizontal = group[0].fromNormal.x !== 0;
        // How far the run reaches along the node edge it leaves from.
        const reach = (item: RoutedEdge): [number, number] =>
            horizontal
                ? [Math.min(item.from.y, item.to.y), Math.max(item.from.y, item.to.y)]
                : [Math.min(item.from.x, item.to.x), Math.max(item.from.x, item.to.x)];

        const lanes: RoutedEdge[][] = [];
        const laneOf = new Map<number, number>();
        const ordered = [...group].sort((a, b) => b.distance - a.distance || a.signed - b.signed);
        for (const item of ordered) {
            const span = reach(item);
            const fits = (lane: number): boolean =>
                lanes[lane].every((other) => {
                    const [start, end] = reach(other);
                    return span[0] >= end || span[1] <= start;
                });
            // Overlap is the only thing a lane cannot have: two runs on one line hide each
            // other, where two runs that cross stay readable. Taken longest first, first fit,
            // that lands on the fewest lanes the corridor can be drawn with.
            let lane = lanes.findIndex((_, i) => fits(i));
            if (lane === -1) {
                lane = lanes.push([]) - 1;
            }
            lanes[lane].push(item);
            laneOf.set(item.id, lane);
        }

        // One corridor, so one set of lines: measured from its near edge, not from each
        // run's own direction, or a run drawn leftward would count its lanes backwards.
        const ends = group.flatMap((item) => (horizontal ? [item.from.x, item.to.x] : [item.from.y, item.to.y]));
        const near = Math.min(...ends);
        const far = Math.max(...ends);

        // Separate lanes only keep the runs themselves apart. Each run also has two tails
        // tying its lane to a corridor face, and the tails of a leftward and a rightward
        // edge that end level lie on one line: they stay apart only when the lane of the
        // edge entering the near face sits nearer than the lane of the edge entering the
        // far face. Renumber the lanes to honour those orderings, so a run never doubles
        // back over the tail of a level neighbour approaching from the other side.
        const tailsOf = (item: RoutedEdge): { at: number; fromNear: boolean }[] => {
            const middle = (near + far) / 2;
            return horizontal
                ? [
                      { at: item.from.y, fromNear: item.from.x < middle },
                      { at: item.to.y, fromNear: item.to.x < middle },
                  ]
                : [
                      { at: item.from.x, fromNear: item.from.y < middle },
                      { at: item.to.x, fromNear: item.to.y < middle },
                  ];
        };
        const precedes = lanes.map(() => new Set<number>());
        for (let i = 0; i < group.length; i++) {
            for (let j = i + 1; j < group.length; j++) {
                const laneA = laneOf.get(group[i].id)!;
                const laneB = laneOf.get(group[j].id)!;
                if (laneA === laneB) continue;
                for (const tailA of tailsOf(group[i])) {
                    for (const tailB of tailsOf(group[j])) {
                        if (Math.abs(tailA.at - tailB.at) >= 0.5 || tailA.fromNear === tailB.fromNear) continue;
                        const [nearLane, farLane] = tailA.fromNear ? [laneA, laneB] : [laneB, laneA];
                        precedes[nearLane].add(farLane);
                    }
                }
            }
        }
        const position = laneOrder(precedes);

        for (const item of group) {
            item.bend = near + ((far - near) * (position[laneOf.get(item.id)!] + 1)) / (lanes.length + 1);
        }
    }

    // Detours stack outwards so several between the same roots stay apart.
    const detourGroups = new Map<number, RoutedEdge[]>();
    for (const item of routed) {
        if (!item.detour) continue;
        const key = item.sourceBox.minX;
        (detourGroups.get(key) ?? detourGroups.set(key, []).get(key)!).push(item);
    }
    for (const [columnLeft, group] of detourGroups) {
        group.sort((a, b) => a.from.y - b.from.y);
        group.forEach((item, i) => {
            item.bend = columnRight(columnLeft) + LANE_MARGIN + i * BEND_SPACING;
        });
    }

    // The midpoint between two columns two apart lands exactly on the column between them.
    for (const item of routed) {
        if (item.detour || item.fromNormal.x === 0) continue;
        item.bend = intoLane(item.bend ?? (item.from.x + item.to.x) / 2, columns, item.from.x, item.to.x);
    }

    return geometries;
}
