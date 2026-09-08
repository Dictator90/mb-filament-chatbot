import { createRoot } from 'react-dom/client';
import '@xyflow/react/dist/style.css';
import './graph/editor.css';

import Editor from './graph/Editor';
import { configure } from './graph/types';

/*
 * Mounts the scenario canvas and keeps every other file ignorant of Livewire.
 *
 * The canvas talks to the server through exactly two calls, and they are handed
 * in as plain functions. Nothing inside `graph/` imports Livewire, knows the
 * name of a Livewire method, or could accidentally start doing so — which is
 * what keeps the canvas testable and portable, and what stops a redraw from
 * turning into a round trip.
 */

const ROOT_ID = 'chatbot-graph-root';

function read(element, attribute) {
    try {
        return JSON.parse(element.dataset[attribute] || 'null');
    } catch (error) {
        console.error(`[chatbot] could not read the ${attribute} the page passed in`, error);

        return null;
    }
}

/**
 * The Livewire component that owns this element, if any.
 *
 * Found by walking up rather than by id, so the page can place the canvas
 * wherever it likes inside the component without telling the script about it.
 */
function componentFor(element) {
    const host = element.closest('[wire\\:id]');
    const id = host?.getAttribute('wire:id');

    return id && window.Livewire ? window.Livewire.find(id) : null;
}

function mount() {
    const element = document.getElementById(ROOT_ID);

    if (!element || element.dataset.mounted === 'yes') {
        return;
    }

    const config = read(element, 'config');
    const graph = read(element, 'graph');

    if (!config || !graph) {
        return;
    }

    /*
     * The sandbox payload may legitimately be absent — a host embedding the
     * canvas of its own accord passes the two it is documented to pass — so it
     * is read separately and never guards the mount.
     */
    const sandbox = read(element, 'sandbox');

    configure(config);

    const component = componentFor(element);

    const wire = {
        save: (payload) => component?.call('saveGraph', payload),
        check: (payload) => component?.call('checkGraph', payload),
        say: (said) => component?.call('sandboxSay', said),
        reset: () => component?.call('sandboxReset'),
        recipe: (key) => component?.call('recipeGraph', key),
    };

    element.dataset.mounted = 'yes';

    const root = createRoot(element);

    root.render(<Editor config={config} graph={graph} sandbox={sandbox} wire={wire} />);

    /*
     * Livewire replaces the page on navigation without reloading it, which
     * leaves a React root attached to an element that is about to be discarded.
     * Unmounting on the way out is what stops two canvases from existing at
     * once — the second one silently overwriting the first one's saves.
     */
    document.addEventListener(
        'livewire:navigating',
        () => {
            root.unmount();
            delete element.dataset.mounted;
        },
        { once: true },
    );
}

document.addEventListener('DOMContentLoaded', mount);
document.addEventListener('livewire:navigated', mount);
