/*
 * What the canvas knows about a kind of node.
 *
 * All of it comes from the server, once per page, and none of it is duplicated
 * here. How many outputs a node has, what its branches are called, what colour
 * it is and which settings it offers are decided by the step type in PHP — the
 * same class the engine runs. Keep a second copy in the browser and the arrow
 * drawn on the canvas eventually stops matching where the bot actually goes,
 * which is the one bug nobody can see by looking.
 *
 * It also means a node written by a host application needs no JavaScript at
 * all: it arrives in this dictionary like any other.
 *
 * The dictionary is a module variable rather than React context. There is one
 * canvas on the page, the lookup happens on every repaint of every node, and a
 * context read for an unchanging table is work plus a provider to forget.
 */

/*
 * The two fixed socket names are display only.
 *
 * An edge remembers which socket it leaves from by *position*, never by this
 * text — see `pruneEdges` in `model.js` — which is what makes it safe to say
 * them in the reader's language.
 */
const NEXT_SLOT = 'next';
const ELSE_SLOT = 'otherwise';

let types = {};
let canvas = {};
let defaults = {};
let strings = {};

/**
 * Stores the dictionaries the server sent. Called once, before the first paint.
 */
export function configure(config) {
    types = {};

    (config.types || []).forEach((type) => {
        types[type.value] = type;
    });

    canvas = config.canvas || {};
    defaults = config.defaults || {};
    strings = config.strings || {};
}

/**
 * One of the canvas's own labels, in the application's language.
 *
 * The wording arrives from the server with the rest of the configuration, for
 * the reason recorded beside `strings()` in `src/Bot/Graph/GraphConfig.php`:
 * this bundle is built once and committed, so a language compiled into it would
 * be whichever one the release happened to be cut in.
 *
 * A key the server did not send falls back to the key itself rather than to an
 * empty string. Both are wrong and only one of them is visible: an empty button
 * reads as a broken canvas, while "add_branch" reads as a missing translation
 * and sends whoever sees it to the right file.
 */
export function text(key, replacements) {
    let line = strings[key] || key;

    Object.entries(replacements || {}).forEach(([name, value]) => {
        line = line.split(`:${name}`).join(String(value));
    });

    return line;
}

/**
 * The description of a kind of node, or null when nothing claims it.
 *
 * Null is an ordinary answer. A scenario may hold nodes from a package that has
 * since been removed, and the canvas has to open anyway — drawn as a problem,
 * so the author can decide, rather than not drawn at all.
 */
export function describe(type) {
    return types[type] || null;
}

export function allTypes() {
    return Object.values(types);
}

export function basicTypes() {
    return Object.values(types).filter((type) => type.basic);
}

/**
 * The labels of a node's outputs, in slot order.
 *
 * This is the single place that turns "what kind of outputs" into "how many
 * sockets and what they are called", and every part of the canvas asks it
 * rather than working it out again.
 */
export function outputLabels(type, options) {
    const described = describe(type);
    const branches = (options || []).map(
        (option, index) => option.label || text('branch_default', { number: index + 1 }),
    );

    if (!described) {
        return [];
    }

    switch (described.outputs) {
        case 'none':
            return [];
        case 'pair':
            return described.pair_labels || ['ok', 'failed'];
        case 'options':
            return branches;
        case 'options_else':
            return [...branches, text(ELSE_SLOT)];
        default:
            return [text(NEXT_SLOT)];
    }
}

export function colorOf(type) {
    return describe(type)?.color || '#64748b';
}

export function iconOf(type) {
    return describe(type)?.icon || null;
}

/**
 * The node's icon as drawable markup, rendered from its name on the server.
 *
 * `iconOf` returns the *name*, which is of no use in a browser that has no icon
 * set installed. This returns the drawing.
 */
export function iconSvgOf(type) {
    return describe(type)?.icon_svg || null;
}

export function labelOf(type) {
    return describe(type)?.label || type;
}

/**
 * Whether the node's own text is what the visitor hears.
 *
 * Drives whether the canvas offers a message box at all. A node whose words come
 * from somewhere else — a knowledge article, a model's reply — would otherwise
 * show a box that goes nowhere.
 */
export function speaks(type) {
    return Boolean(describe(type)?.speaks);
}

export function fieldsOf(type) {
    return describe(type)?.fields || [];
}

/**
 * What a freshly dropped node of this kind starts with.
 */
export function defaultsFor(type) {
    const config = {};

    fieldsOf(type).forEach((field) => {
        if (field.default !== undefined && field.default !== null) {
            config[field.name] = field.default;
        }
    });

    return config;
}

/**
 * The kinds usually placed after this one, resolved to descriptions.
 *
 * An empty canvas asks "where do I start"; a canvas with a node selected asks
 * "what next", and answering the second question with the full list of kinds is
 * not answering it.
 */
export function nextTypes(type) {
    return (describe(type)?.next_types || [])
        .map((key) => describe(key))
        .filter(Boolean);
}

export function canvasSettings() {
    return canvas;
}

export function editorDefaults() {
    return defaults;
}

export { ELSE_SLOT, NEXT_SLOT };
