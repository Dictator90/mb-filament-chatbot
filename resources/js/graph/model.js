import { defaultsFor, outputLabels } from './types';

/*
 * Translates between the shape the server saves and the shape React Flow draws.
 *
 * The two are deliberately different. The server stores a scenario the way the
 * engine walks it — a node's next step lives on the node — while the canvas
 * needs edges as first-class objects it can drag. Everything that converts
 * between them lives here, so that a change to either shape has exactly one
 * place to be reflected.
 *
 * There is no edge table on the server, and that is the whole design: an arrow
 * is derived from a node's own outputs. Which socket maps to which field is
 * decided by the step type, and the browser never guesses it — see `types.js`.
 */

export const IN_HANDLE = 'in';

export function outHandle(slot) {
    return `out-${slot}`;
}

export function handleIndex(handle) {
    const match = /^out-(\d+)$/.exec(handle || '');

    return match ? Number(match[1]) : null;
}

/**
 * Server graph to React Flow nodes and edges.
 */
export function toFlow(graph, problems = {}) {
    const nodes = (graph.nodes || []).map((node) => ({
        id: node.key,
        type: 'step',
        position: { x: node.x || 0, y: node.y || 0 },
        data: {
            key: node.key,
            type: node.type,
            body: node.body || '',
            config: node.config || {},
            options: node.options || [],
            problem: problems[node.key] || null,
        },
    }));

    const notes = (graph.notes || []).map((note, index) => ({
        id: `note-${index}`,
        type: 'note',
        position: { x: note.x || 0, y: note.y || 0 },
        data: { text: note.text || '' },
        selectable: true,
        draggable: true,
    }));

    const edges = (graph.edges || []).map((edge) => ({
        id: `${edge.from}:${edge.slot}:${edge.to}`,
        source: edge.from,
        target: edge.to,
        sourceHandle: outHandle(edge.slot),
        targetHandle: IN_HANDLE,
    }));

    return { nodes: [...nodes, ...notes], edges };
}

/**
 * React Flow back to the shape the server saves.
 *
 * Coordinates are rounded to whole pixels. Sub-pixel drift from dragging would
 * otherwise make every save a change, and "unpublished changes" would light up
 * because somebody nudged a node while reading.
 */
export function toPayload(nodes, edges, extra = {}) {
    const steps = nodes.filter((node) => node.type === 'step');

    return {
        version: 1,
        nodes: steps.map((node) => ({
            key: node.data.key,
            type: node.data.type,
            body: node.data.body || '',
            config: node.data.config || {},
            options: node.data.options || [],
            x: Math.round(node.position.x),
            y: Math.round(node.position.y),
        })),
        edges: edges
            .map((edge) => ({
                from: edge.source,
                slot: handleIndex(edge.sourceHandle),
                to: edge.target,
            }))
            .filter((edge) => edge.slot !== null),
        notes: nodes
            .filter((node) => node.type === 'note')
            .map((node) => ({
                text: node.data.text || '',
                x: Math.round(node.position.x),
                y: Math.round(node.position.y),
            })),
        ...extra,
    };
}

/**
 * Drops edges that no longer have a socket to leave from.
 *
 * Sockets come and go while a scenario is edited: a branch is removed, a node's
 * kind is changed. An edge left hanging off a socket that no longer exists is
 * invisible on the canvas and perfectly real in the saved scenario, which is the
 * worst combination available.
 */
export function pruneEdges(nodes, edges) {
    const sockets = new Map(
        nodes
            .filter((node) => node.type === 'step')
            .map((node) => [node.id, outputLabels(node.data.type, node.data.options).length]),
    );

    return edges.filter((edge) => {
        const count = sockets.get(edge.source);
        const slot = handleIndex(edge.sourceHandle);

        return count !== undefined && slot !== null && slot < count && sockets.has(edge.target);
    });
}

/**
 * A key nothing else on the canvas is using.
 *
 * Derived from the kind rather than random, because the key is what an author
 * sees in the run journal and in error messages: "ask-2" is a place in a
 * scenario, "n_f3a9c1" is a puzzle.
 */
export function freeKey(nodes, type) {
    const taken = new Set(nodes.map((node) => node.id));
    const base = String(type).replace(/[^a-z0-9]+/gi, '-').toLowerCase();

    if (!taken.has(base)) {
        return base;
    }

    let index = 2;

    while (taken.has(`${base}-${index}`)) {
        index += 1;
    }

    return `${base}-${index}`;
}

export function newNode(type, position, nodes) {
    const key = freeKey(nodes, type);

    return {
        id: key,
        type: 'step',
        position,
        data: {
            key,
            type,
            body: '',
            config: defaultsFor(type),
            options: [],
            problem: null,
        },
    };
}

/**
 * Problems from the server, keyed by node.
 *
 * Blocking wins over a notice on the same node: a node with both has one badge,
 * and the one worth drawing is the one that stops a release.
 */
export function problemsByKey(issues = []) {
    const problems = {};

    issues.forEach((issue) => {
        if (!issue.key) {
            return;
        }

        const existing = problems[issue.key];

        if (!existing || (existing.severity !== 'blocking' && issue.severity === 'blocking')) {
            problems[issue.key] = { severity: issue.severity, message: issue.message };
        }
    });

    return problems;
}

/**
 * Whether the graph has exactly one entry point.
 */
export function hasStart(nodes) {
    return nodes.filter((node) => node.type === 'step' && node.data.type === 'start').length === 1;
}

/**
 * Drops a recipe's nodes onto a canvas that already has something on it.
 *
 * **Every key is renamed against what is already there, and the arrows are
 * renamed with them.** A recipe's own keys are what the recipe author wrote;
 * inserted next to a scenario that already has a node called `start`, they
 * collide, and a collision here is not a duplicate node — it is one node
 * silently replacing another, with the arrows of both pointing at whichever
 * survived.
 *
 * The rename is the same `freeKey` an author gets when adding a node by hand, so
 * an inserted `ask` becomes `ask-2` exactly as it would if they had clicked the
 * button — and the key stays something readable in the run journal rather than
 * a random string.
 *
 * A second start node is dropped rather than renamed. A scenario has exactly one
 * entry point; a recipe carrying its own would arrive as `start-2`, which is a
 * node nothing can ever reach and a defect the author then has to be told about.
 * Onto an empty canvas its start is the first one and is kept.
 */
export function insertRecipe(recipe, nodes, edges) {
    const alreadyStarted = hasStart(nodes);
    const coming = (recipe.nodes || []).filter(
        (node) => !(alreadyStarted && node.type === 'start'),
    );

    const renamed = {};
    let taken = nodes;

    const added = coming.map((node) => {
        const key = freeKey(taken, node.key);

        renamed[node.key] = key;

        const placed = {
            id: key,
            type: 'step',
            position: { x: 0, y: 0 },
            data: {
                key,
                type: node.type,
                body: node.body || '',
                config: node.config || {},
                options: node.options || [],
                problem: null,
            },
        };

        taken = [...taken, placed];

        return placed;
    });

    const addedEdges = (recipe.edges || [])
        .filter((edge) => renamed[edge.from] && renamed[edge.to])
        .map((edge) => ({
            id: `${renamed[edge.from]}:${edge.slot}:${renamed[edge.to]}`,
            source: renamed[edge.from],
            target: renamed[edge.to],
            sourceHandle: outHandle(edge.slot),
            targetHandle: IN_HANDLE,
        }));

    return { nodes: [...nodes, ...added], edges: [...edges, ...addedEdges] };
}
