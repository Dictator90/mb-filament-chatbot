import dagre from '@dagrejs/dagre';

import { canvasSettings } from './types';

/*
 * Places the nodes of a scenario along the direction the installation chose.
 *
 * Down the page by default. A canvas that grows downwards grows into space the
 * window already scrolls through; one that grows sideways runs off the edge,
 * and every branch past the third is found by panning rather than by looking.
 * The direction is `chatbot.canvas.layout.direction`, so an installation whose
 * scenarios are short and wide can have them laid out across instead — the
 * trade is real either way, and it is not this file's to settle.
 *
 * Every distance here comes from the same place, for the same reason: the
 * numbers that decide how a scenario is arranged belong where somebody can read
 * them, not inside a bundle that has to be rebuilt to change them.
 */

function layoutSettings() {
    const layout = canvasSettings()?.layout || {};

    return {
        direction: layout.direction === 'LR' ? 'LR' : 'TB',
        rankGap: Number(layout.rank_gap) || 120,
        nodeGap: Number(layout.node_gap) || 48,
        unreachableGap: Number(layout.unreachable_gap) || 160,
    };
}

/*
 * Used only until React Flow has measured a node for real.
 *
 * Guessing the size is what makes an auto-arrange overlap: a node with a long
 * label is twice as tall as the guess, and dagre packs the next rank straight
 * through it. So measurements win whenever they exist, and these numbers only
 * cover the first arrange of a node that has not been painted yet.
 */
const FALLBACK_WIDTH = 240;
const FALLBACK_HEIGHT = 120;

function sizeOf(node) {
    return {
        width: node.measured?.width || node.width || FALLBACK_WIDTH,
        height: node.measured?.height || node.height || FALLBACK_HEIGHT,
    };
}

/**
 * The keys reachable from every start node, following edges forward.
 *
 * Separated out because an unreachable node is not a mistake to be hidden: a
 * branch is unreachable for the whole time it is being built. Mixed into the
 * main flow dagre gives it a rank of its own and pushes the actual scenario
 * sideways, so the work in progress rearranges the finished part.
 */
function reachableKeys(steps, edges) {
    const outgoing = new Map();

    edges.forEach((edge) => {
        if (!outgoing.has(edge.source)) {
            outgoing.set(edge.source, []);
        }

        outgoing.get(edge.source).push(edge.target);
    });

    const seen = new Set();
    const queue = steps.filter((node) => node.data.type === 'start').map((node) => node.id);

    queue.forEach((key) => seen.add(key));

    while (queue.length > 0) {
        const key = queue.shift();

        (outgoing.get(key) || []).forEach((target) => {
            if (!seen.has(target)) {
                seen.add(target);
                queue.push(target);
            }
        });
    }

    return seen;
}

function layoutOf(steps, edges) {
    const { direction, rankGap, nodeGap } = layoutSettings();
    const graph = new dagre.graphlib.Graph();

    graph.setGraph({ rankdir: direction, ranksep: rankGap, nodesep: nodeGap });
    graph.setDefaultEdgeLabel(() => ({}));

    const present = new Set(steps.map((node) => node.id));

    steps.forEach((node) => {
        graph.setNode(node.id, sizeOf(node));
    });

    edges
        .filter((edge) => present.has(edge.source) && present.has(edge.target))
        .forEach((edge) => {
            graph.setEdge(edge.source, edge.target);
        });

    dagre.layout(graph);

    return graph;
}

/**
 * The rectangle a laid-out dagre graph occupies, in node coordinates.
 */
function extentOf(graph, steps) {
    let bottom = 0;
    let left = Number.POSITIVE_INFINITY;

    steps.forEach((node) => {
        const placed = graph.node(node.id);

        if (!placed) {
            return;
        }

        bottom = Math.max(bottom, placed.y + placed.height / 2);
        left = Math.min(left, placed.x - placed.width / 2);
    });

    return {
        bottom: Number.isFinite(bottom) ? bottom : 0,
        left: Number.isFinite(left) ? left : 0,
    };
}

/**
 * Re-places every step; notes stay exactly where the author left them.
 *
 * A note is put next to something on purpose — "this branch is temporary",
 * pinned to that branch. Moving it with the rest turns every auto-arrange into
 * a round of guessing which node a comment used to be about.
 */
export function arrange(nodes, edges) {
    const steps = nodes.filter((node) => node.type === 'step');

    if (steps.length === 0) {
        return nodes;
    }

    const reachable = reachableKeys(steps, edges);
    const main = steps.filter((node) => reachable.has(node.id));
    const stranded = steps.filter((node) => !reachable.has(node.id));

    const mainGraph = layoutOf(main, edges);
    const placed = new Map();

    main.forEach((node) => {
        const box = mainGraph.node(node.id);

        if (box) {
            placed.set(node.id, {
                x: Math.round(box.x - box.width / 2),
                y: Math.round(box.y - box.height / 2),
            });
        }
    });

    if (stranded.length > 0) {
        const extent = extentOf(mainGraph, main);
        const strandedGraph = layoutOf(stranded, edges);
        const strandedExtent = extentOf(strandedGraph, stranded);
        const offsetY = (main.length > 0 ? extent.bottom : 0) + layoutSettings().unreachableGap;

        stranded.forEach((node) => {
            const box = strandedGraph.node(node.id);

            if (box) {
                placed.set(node.id, {
                    x: Math.round(box.x - box.width / 2 - strandedExtent.left + extent.left),
                    y: Math.round(box.y - box.height / 2 + offsetY),
                });
            }
        });
    }

    return nodes.map((node) => (placed.has(node.id) ? { ...node, position: placed.get(node.id) } : node));
}
