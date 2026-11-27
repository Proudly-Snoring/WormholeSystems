import type { MapStore } from '@/map/store/mapStore';
import type { TMapSolarsystem } from '@/pages/maps';

/**
 * The systems "Clean map" removes: dead branches that are neither pinned, the
 * home system, nor reachable from one of those anchors through the connection
 * graph.
 */
export function getOrphanedMapSolarsystems(store: MapStore): TMapSolarsystem[] {
    const homeSolarsystemId = store.meta.value?.home_solarsystem_id ?? null;

    const adjacency = new Map<number, number[]>();
    for (const connection of store.connections.values()) {
        const { from_map_solarsystem_id: from, to_map_solarsystem_id: to } = connection;
        adjacency.set(from, [...(adjacency.get(from) ?? []), to]);
        adjacency.set(to, [...(adjacency.get(to) ?? []), from]);
    }

    const queue = [...store.systems.values()]
        .filter((system) => system.pinned || system.solarsystem_id === homeSolarsystemId)
        .map((system) => system.id);
    const reachable = new Set(queue);

    while (queue.length > 0) {
        const id = queue.pop()!;
        for (const neighbor of adjacency.get(id) ?? []) {
            if (!reachable.has(neighbor)) {
                reachable.add(neighbor);
                queue.push(neighbor);
            }
        }
    }

    return [...store.systems.values()].filter((system) => !reachable.has(system.id));
}
