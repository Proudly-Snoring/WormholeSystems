import { nodeRect } from '@/map/core/coords';
import { computeTreeEdgeGeometries, edgeCenterConnection, LANE_MARGIN, PARALLEL_SPACING } from '@/map/core/geometry/treeRouting';
import type { EdgeGeometry, EdgeInput, Rect, Vec2 } from '@/map/core/types';
import { describe, expect, it } from 'vitest';

const SIZE = { width: 180, height: 40 };

function rectAt(anchor: Vec2): Rect {
    return nodeRect(anchor, SIZE);
}

function rectsAt(anchors: Record<number, Vec2>): Map<number, Rect> {
    return new Map(Object.entries(anchors).map(([id, anchor]) => [Number(id), rectAt(anchor)]));
}

function edge(id: number, sourceId: number, targetId: number): EdgeInput {
    return { id, sourceId, targetId };
}

function elbow(geometry: EdgeGeometry | undefined) {
    if (!geometry || geometry.kind !== 'elbow') throw new Error('expected an elbow geometry');
    return geometry;
}

/** The offset of the elbow's perpendicular run, defaulted like the path builder does. */
function bendOf(geometry: EdgeGeometry | undefined): number {
    const g = elbow(geometry);
    return g.bend ?? (g.fromNormal.x !== 0 ? (g.from.x + g.to.x) / 2 : (g.from.y + g.to.y) / 2);
}

describe('edgeCenterConnection', () => {
    it('uses left/right edges for horizontally separated boxes', () => {
        const source = rectAt({ x: 100, y: 100 });
        const target = rectAt({ x: 500, y: 300 });

        const ends = edgeCenterConnection(source, target);
        expect(ends.from).toEqual({ x: source.maxX, y: source.centerY });
        expect(ends.to).toEqual({ x: target.minX, y: target.centerY });
        expect(ends.fromNormal).toEqual({ x: 1, y: 0 });
        expect(ends.toNormal).toEqual({ x: -1, y: 0 });
    });

    it('mirrors for right-to-left connections', () => {
        const source = rectAt({ x: 500, y: 100 });
        const target = rectAt({ x: 100, y: 100 });

        const ends = edgeCenterConnection(source, target);
        expect(ends.from.x).toBe(source.minX);
        expect(ends.to.x).toBe(target.maxX);
        expect(ends.fromNormal).toEqual({ x: -1, y: 0 });
    });

    it('uses top/bottom edges only when the boxes share a column', () => {
        const source = rectAt({ x: 100, y: 100 });
        const target = rectAt({ x: 100, y: 400 });

        const ends = edgeCenterConnection(source, target);
        expect(ends.from).toEqual({ x: source.centerX, y: source.maxY });
        expect(ends.to).toEqual({ x: target.centerX, y: target.minY });
        expect(ends.fromNormal).toEqual({ x: 0, y: 1 });
    });
});

describe('computeTreeEdgeGeometries', () => {
    it('falls back to a curve between anchors while a node is unmeasured', () => {
        const rects = rectsAt({ 1: { x: 100, y: 100 } });
        const anchors = new Map([
            [1, { x: 100, y: 100 }],
            [2, { x: 500, y: 100 }],
        ]);

        const geometries = computeTreeEdgeGeometries([edge(7, 1, 2)], rects, anchors);
        expect(geometries.get(7)).toEqual({ id: 7, kind: 'curve', from: { x: 100, y: 100 }, to: { x: 500, y: 100 } });
    });

    it('drops edges with no measured box and no anchor', () => {
        const geometries = computeTreeEdgeGeometries([edge(7, 1, 2)], new Map(), new Map());
        expect(geometries.size).toBe(0);
    });

    it('spreads endpoints sharing a node edge PARALLEL_SPACING apart, ordered by the far end', () => {
        // One hub with two targets to its right, one above and one below.
        const rects = rectsAt({
            1: { x: 100, y: 300 },
            2: { x: 500, y: 100 },
            3: { x: 500, y: 500 },
        });
        const geometries = computeTreeEdgeGeometries([edge(10, 1, 2), edge(11, 1, 3)], rects, new Map());

        const up = elbow(geometries.get(10));
        const down = elbow(geometries.get(11));
        const hubCenterY = rects.get(1)!.centerY;
        // The link to the upper target leaves above the centreline, the lower below.
        expect(up.from.y).toBe(hubCenterY - PARALLEL_SPACING / 2);
        expect(down.from.y).toBe(hubCenterY + PARALLEL_SPACING / 2);
        // Both still leave the hub's right edge.
        expect(up.from.x).toBe(rects.get(1)!.maxX);
        expect(down.from.x).toBe(rects.get(1)!.maxX);
    });

    it('goes out of the top or bottom when the nodes share a clear column', () => {
        const rects = rectsAt({
            1: { x: 100, y: 100 },
            2: { x: 100, y: 460 },
        });
        const g = elbow(computeTreeEdgeGeometries([edge(10, 1, 2)], rects, new Map()).get(10));

        // Nothing in the way, so it takes the short way: out of the bottom, into the top.
        expect(g.from).toEqual({ x: rects.get(1)!.centerX, y: rects.get(1)!.maxY });
        expect(g.to).toEqual({ x: rects.get(2)!.centerX, y: rects.get(2)!.minY });
    });

    it('detours into the lane when nodes sit between the two in a column', () => {
        // Four stacked systems in one column, and a connection joining the outer two:
        // straight down would vanish behind the two in between.
        const rects = rectsAt({
            1: { x: 100, y: 100 },
            2: { x: 100, y: 220 },
            3: { x: 100, y: 340 },
            4: { x: 100, y: 460 },
        });
        const g = elbow(computeTreeEdgeGeometries([edge(10, 1, 4)], rects, new Map()).get(10));

        // Both ends leave the same side, and the run happens beside the column.
        const columnRight = rects.get(1)!.maxX;
        expect(g.from).toEqual({ x: columnRight, y: rects.get(1)!.centerY });
        expect(g.to).toEqual({ x: columnRight, y: rects.get(4)!.centerY });
        expect(g.bend).toBe(columnRight + LANE_MARGIN);
    });

    it('never runs vertically through a column it is only passing', () => {
        // Columns two apart: the midpoint between them is the column in between, which is
        // exactly where a naive bend would put the vertical run. The middle system is not
        // even connected — it still has to be routed around.
        const rects = rectsAt({
            1: { x: 100, y: 100 },
            2: { x: 420, y: 400 },
            3: { x: 740, y: 200 },
        });
        const g = computeTreeEdgeGeometries([edge(10, 1, 3)], rects, new Map()).get(10);

        expect(bendOf(g)).toBe(rects.get(2)!.maxX + LANE_MARGIN);
    });
});

describe('runs share a lane where they can', () => {
    // A run up and a run down never overlap, so they can sit on the same line and still be
    // told apart: each keeps its own stroke. Giving them separate lines made a two-hole
    // system look like a ladder.
    it('puts one child above and one below on the same line', () => {
        const rects = rectsAt({
            1: { x: 100, y: 300 },
            2: { x: 420, y: 180 },
            3: { x: 420, y: 420 },
        });
        const geometries = computeTreeEdgeGeometries([edge(10, 1, 2), edge(11, 1, 3)], rects, new Map());

        expect(bendOf(geometries.get(10))).toBe(bendOf(geometries.get(11)));
    });

    // Two runs the same way do overlap, and would hide each other.
    it('keeps two children on the same side apart', () => {
        const rects = rectsAt({
            1: { x: 100, y: 300 },
            2: { x: 420, y: 60 },
            3: { x: 420, y: 180 },
        });
        const geometries = computeTreeEdgeGeometries([edge(10, 1, 2), edge(11, 1, 3)], rects, new Map());

        expect(bendOf(geometries.get(10))).not.toBe(bendOf(geometries.get(11)));
    });

    // A tree layout centres a parent between its two children, so the gap either side is
    // small. Every such parent in a column should kink on the same line: a map of them
    // kinking a few pixels apart reads as noise.
    it('lines up the kink for every parent in a column', () => {
        const anchors: Record<number, Vec2> = {};
        const edges: EdgeInput[] = [];
        let id = 0;
        let next = 1;
        [202, 321, 441, 561, 681].forEach((parentY, i) => {
            const parent = next++;
            anchors[parent] = { x: 100, y: parentY };
            for (const childY of [161 + i * 120, 221 + i * 120]) {
                const child = next++;
                anchors[child] = { x: 420, y: childY };
                edges.push(edge(id++, parent, child));
            }
        });
        const geometries = computeTreeEdgeGeometries(edges, rectsAt(anchors), new Map());

        expect(new Set(edges.map((e) => bendOf(geometries.get(e.id)))).size).toBe(1);
    });
});

describe('a hub with many children in the next column', () => {
    const ys = [40, 100, 167, 227, 287, 347, 407, 467, 527, 587, 647, 707, 767];

    function hub() {
        const anchors: Record<number, Vec2> = { 1: { x: 100, y: 410 } };
        const edges = ys.map((y, i) => {
            anchors[i + 2] = { x: 420, y };
            return edge(10 + i, 1, i + 2);
        });
        return { edges, geometries: computeTreeEdgeGeometries(edges, rectsAt(anchors), new Map()) };
    }

    // The bend used to step a fixed distance from the middle, which walked the outermost
    // runs onto the target column: they were then shunted past it and doubled back.
    it('keeps every run between the two columns', () => {
        const { edges, geometries } = hub();
        const hubRight = rectAt({ x: 100, y: 410 }).maxX;
        const targetLeft = rectAt({ x: 420, y: 40 }).minX;
        for (const e of edges) {
            const bend = bendOf(geometries.get(e.id));
            expect(bend).toBeGreaterThan(hubRight);
            expect(bend).toBeLessThan(targetLeft);
        }
    });

    it('never draws two runs on top of each other', () => {
        const { edges, geometries } = hub();
        const runs = edges.map((e) => {
            const g = elbow(geometries.get(e.id));
            return { bend: bendOf(g), y1: Math.min(g.from.y, g.to.y), y2: Math.max(g.from.y, g.to.y) };
        });
        for (let i = 0; i < runs.length; i++) {
            for (let j = i + 1; j < runs.length; j++) {
                if (Math.abs(runs[i].bend - runs[j].bend) > 0.5) continue;
                const lo = Math.max(runs[i].y1, runs[j].y1);
                const hi = Math.min(runs[i].y2, runs[j].y2);
                expect(hi - lo).toBeLessThanOrEqual(0.5);
            }
        }
    });

    // Thirteen holes, six of them above the node and six below. Every run above overlaps
    // every other run above near the node, so six lines is the fewest it can be drawn with.
    it('uses no more lines than the runs actually need', () => {
        const { edges, geometries } = hub();
        expect(new Set(edges.map((e) => Math.round(bendOf(geometries.get(e.id))))).size).toBe(6);
    });
});

// Distilled from map 1: 23→233 (leftward into the left column) ended level with 5→53
// (rightward into the right column), and packing order alone put the leftward edge's lane
// right of the rightward edge's, so their level tails overlapped between the two bends.
describe('level tails from opposite sides stay apart', () => {
    it('keeps the leftward lane left of the rightward lane', () => {
        const rects = rectsAt({
            1: { x: 100, y: 1000 }, // hub with two children, its ports spread
            2: { x: 420, y: 440 },
            3: { x: 420, y: 1060 },
            4: { x: 420, y: 640 }, // sends an edge back left, level with the hub's lower child
            5: { x: 100, y: 1060 },
        });
        const geometries = computeTreeEdgeGeometries([edge(20, 1, 2), edge(21, 1, 3), edge(22, 4, 5)], rects, new Map());

        const rightward = elbow(geometries.get(21));
        const leftward = elbow(geometries.get(22));
        // Both end at the same y on opposite faces of the corridor, in different lanes.
        expect(rightward.to.y).toBe(leftward.to.y);
        expect(bendOf(rightward)).not.toBe(bendOf(leftward));
        // The tails [near, leftward.bend] and [rightward.bend, far] must not overlap.
        expect(bendOf(leftward)).toBeLessThan(bendOf(rightward));
    });
});

// A node with two holes spreads its ends apart so they can be told apart. A neighbour level
// with it, holding only this one hole, has nothing to spread, so the two ends used to sit a
// few pixels apart and the line kinked on its way across for no reason.
describe('a level run stays straight', () => {
    it('follows the busier end rather than kinking into the middle of the quiet one', () => {
        const rects = rectsAt({
            1: { x: 100, y: 300 },
            2: { x: 500, y: 300 },
            3: { x: 500, y: 460 },
        });
        const level = elbow(computeTreeEdgeGeometries([edge(10, 1, 2), edge(11, 1, 3)], rects, new Map()).get(10));

        expect(level.from.y).toBe(level.to.y);
    });

    // Only a run that would otherwise be straight gets this: a node above or below still
    // has a real bend to make, and pulling its end across would drag the line off its own
    // centre line for nothing.
    it('leaves a run alone when the nodes are not level', () => {
        const rects = rectsAt({
            1: { x: 100, y: 300 },
            2: { x: 500, y: 340 },
            3: { x: 500, y: 460 },
        });
        const g = elbow(computeTreeEdgeGeometries([edge(10, 1, 2), edge(11, 1, 3)], rects, new Map()).get(10));

        expect(g.to.y).toBe(340);
    });
});
