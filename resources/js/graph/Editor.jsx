import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Background,
    Controls,
    MiniMap,
    ReactFlow,
    ReactFlowProvider,
    applyEdgeChanges,
    applyNodeChanges,
    useReactFlow,
} from '@xyflow/react';

import NoteNode from './NoteNode';
import Sandbox from './Sandbox';
import SideForm from './SideForm';
import StepNode from './StepNode';
import StepPicker from './StepPicker';
import { arrange } from './layout';
import {
    IN_HANDLE,
    handleIndex,
    insertRecipe,
    newNode,
    problemsByKey,
    pruneEdges,
    toFlow,
    toPayload,
} from './model';
import { colorOf, describe, text } from './types';

/*
 * The canvas.
 *
 * It owns the draft and nothing else does. Nothing here is written to the
 * server until somebody presses Save, which is what makes it safe to drag,
 * rename and delete while thinking — and why leaving the page has to ask.
 *
 * The two calls to the server are handed in as plain functions, so this file
 * has no idea Livewire exists and cannot start a round trip by accident.
 */

const nodeTypes = { step: StepNode, note: NoteNode };

/**
 * How long two text edits may be apart and still count as one undo step.
 *
 * Without it every keystroke in the message box is its own entry, and undoing a
 * deleted node means holding Ctrl+Z through the sentence typed before it.
 */
const COALESCE_MS = 700;

/**
 * Drops arrows that no longer have a socket to leave from, sparing the ones
 * leaving a node whose kind is not installed.
 *
 * Such a node keeps its settings and its transitions on the server precisely so
 * that editing a scenario does not delete a host package's work. The canvas
 * cannot know how many sockets it has — the kind is gone — so the general rule
 * would say "none", and one ordinary save would erase arrows the author never
 * touched and cannot see.
 */
function prune(nodes, edges) {
    const foreign = new Set(
        nodes.filter((node) => node.type === 'step' && !describe(node.data.type)).map((node) => node.id),
    );
    const present = new Set(nodes.map((node) => node.id));
    const kept = new Set(pruneEdges(nodes, edges).map((edge) => edge.id));

    return edges.filter(
        (edge) =>
            kept.has(edge.id) ||
            (foreign.has(edge.source) && present.has(edge.target) && handleIndex(edge.sourceHandle) !== null),
    );
}

function edgeId(source, handle, target) {
    return `${source}:${handleIndex(handle)}:${target}`;
}

/**
 * A sandbox that has not been started yet.
 *
 * The same shape the server sends back after a turn, so the panel never has two
 * cases to render and a missing payload cannot become `undefined.map`.
 */
const NO_CONVERSATION = { transcript: [], visited: [], trace: [], state: null };

function Canvas({ config, graph, sandbox: opening, wire }) {
    const flow = useReactFlow();
    const wrapper = useRef(null);
    const area = useRef(null);

    const initial = useMemo(() => toFlow(graph), [graph]);

    const [nodes, setNodes] = useState(initial.nodes);
    const [edges, setEdges] = useState(initial.edges);
    const [issues, setIssues] = useState(config.issues || []);
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [selectedId, setSelectedId] = useState(null);
    const [picker, setPicker] = useState(null);
    const [recipesOpen, setRecipesOpen] = useState(false);
    const [sandbox, setSandbox] = useState(opening || NO_CONVERSATION);
    const [sandboxBusy, setSandboxBusy] = useState(false);

    /*
     * The panel opens by itself only for somebody who left a conversation
     * unfinished. Opening it for everybody would take a fifth of the canvas
     * away from the many people who came to draw rather than to talk.
     */
    const [trying, setTrying] = useState((opening?.transcript || []).length > 0);

    /*
     * A mirror of the current draft, read by handlers that were created before
     * the last change. Without it an undo pushed from a stale closure restores
     * a graph two edits old — which looks, to the author, like the canvas
     * losing work at random.
     */
    const current = useRef({ nodes, edges });
    const past = useRef([]);
    const future = useRef([]);
    const lastPush = useRef(0);

    useEffect(() => {
        current.current = { nodes, edges };
    }, [nodes, edges]);

    const remember = useCallback((coalesce = false) => {
        const now = Date.now();

        if (coalesce && now - lastPush.current < COALESCE_MS) {
            return;
        }

        past.current.push(current.current);
        future.current = [];
        lastPush.current = now;
    }, []);

    /**
     * Applies a change to the draft and keeps the arrows honest.
     *
     * Every structural change goes through here, because pruning has to happen
     * on all of them and not only on the obvious ones: removing a branch takes
     * a socket away just as surely as deleting a node does.
     */
    const commit = useCallback(
        (change, { coalesce = false } = {}) => {
            remember(coalesce);
            setDirty(true);

            const next = change(current.current);
            const nextEdges = prune(next.nodes, next.edges);

            current.current = { nodes: next.nodes, edges: nextEdges };
            setNodes(next.nodes);
            setEdges(nextEdges);
        },
        [remember],
    );

    const applyIssues = useCallback((list) => {
        const problems = problemsByKey(list);

        setIssues(list);
        setNodes((previous) =>
            previous.map((node) =>
                node.type === 'step'
                    ? { ...node, data: { ...node.data, problem: problems[node.id] || null } }
                    : node,
            ),
        );
    }, []);

    /**
     * Numbers the nodes the conversation genuinely walked.
     *
     * The keys come from the run journal on the server, never from following the
     * arrows here: which branch was taken is the whole question a sandbox is
     * opened to answer, and a path worked out on the canvas would answer with the
     * branch the author drew — the very thing under test.
     *
     * The number, not just a highlight, because a scenario that loops walks the
     * same node twice and "it was here" is a different fact from "it was here
     * first".
     */
    const applyVisited = useCallback((visited) => {
        const order = new Map((visited || []).map((key, index) => [key, index + 1]));

        setNodes((previous) =>
            previous.map((node) =>
                node.type === 'step'
                    ? { ...node, data: { ...node.data, visit: order.get(node.id) || null } }
                    : node,
            ),
        );
    }, []);

    useEffect(() => {
        applyIssues(config.issues || []);
        applyVisited(opening?.visited);
        // The server's opening verdict is applied once; afterwards the canvas
        // owns the marks and re-running this would wipe a fresh check result.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const selected = nodes.find((node) => node.id === selectedId && node.type === 'step') || null;

    /*
     * The selected node's own connections, drawn as a moving dashed line.
     *
     * What an author checks on a busy canvas is where this node came from and
     * where it can go, and a scenario of thirty nodes is a thicket of identical
     * grey curves — following one by eye means tracing it under the others. The
     * animation is the part that carries: a dashed line that is merely a
     * different colour still has to be found first, while one that moves is seen
     * without looking for it.
     *
     * Both directions, deliberately. Half the question an author is asking is
     * what reaches this node — a branch nothing points at is the commonest
     * mistake on a canvas, and it is invisible unless incoming edges answer too.
     *
     * Built here rather than written into the edges themselves: selection is not
     * part of a scenario, and marking the stored edges would put it into the
     * undo history and into what gets saved.
     */
    const drawnEdges = useMemo(
        () => (selectedId === null
            ? edges
            : edges.map((edge) => (edge.source === selectedId || edge.target === selectedId
                ? { ...edge, animated: true, className: [edge.className, 'is-linked'].filter(Boolean).join(' ') }
                : edge))),
        [edges, selectedId],
    );

    const onNodesChange = useCallback(
        (changes) => {
            /*
             * Selecting and dragging are not worth an undo entry; adding,
             * removing and replacing are. "Replace" is how a note's text comes
             * back from the canvas, and left in the cheap branch it would be a
             * change nothing recorded — no undo, and no warning on the way out
             * of a page whose draft is only in the browser.
             */
            const structural = changes.some((change) =>
                ['remove', 'add', 'replace'].includes(change.type),
            );

            if (structural) {
                commit(({ nodes: currentNodes, edges: currentEdges }) => ({
                    nodes: applyNodeChanges(changes, currentNodes),
                    edges: currentEdges,
                }));

                return;
            }

            setNodes((previous) => {
                const next = applyNodeChanges(changes, previous);

                current.current = { ...current.current, nodes: next };

                return next;
            });
        },
        [commit],
    );

    const onEdgesChange = useCallback(
        (changes) => {
            if (changes.some((change) => change.type === 'remove')) {
                commit(({ nodes: currentNodes, edges: currentEdges }) => ({
                    nodes: currentNodes,
                    edges: applyEdgeChanges(changes, currentEdges),
                }));

                return;
            }

            setEdges((previous) => {
                const next = applyEdgeChanges(changes, previous);

                current.current = { ...current.current, edges: next };

                return next;
            });
        },
        [commit],
    );

    /**
     * One socket, one destination.
     *
     * A step records where each exit goes in a single field, so a second arrow
     * from the same socket cannot survive a save: one of the two would be
     * dropped by the server, and which one is not something an author can
     * predict from the picture. Replacing the old arrow says so immediately.
     */
    const onConnect = useCallback(
        (connection) => {
            if (!connection.source || !connection.target) {
                return;
            }

            commit(({ nodes: currentNodes, edges: currentEdges }) => ({
                nodes: currentNodes,
                edges: [
                    ...currentEdges.filter(
                        (edge) =>
                            !(edge.source === connection.source && edge.sourceHandle === connection.sourceHandle),
                    ),
                    {
                        id: edgeId(connection.source, connection.sourceHandle, connection.target),
                        source: connection.source,
                        target: connection.target,
                        sourceHandle: connection.sourceHandle,
                        targetHandle: IN_HANDLE,
                    },
                ],
            }));
        },
        [commit],
    );

    const positionOf = useCallback(
        (clientX, clientY) => flow.screenToFlowPosition({ x: clientX, y: clientY }),
        [flow],
    );

    /**
     * Opens the kind picker, and decides where the node it creates will land.
     *
     * Both coordinates are taken from the canvas rectangle, not from the page.
     * The toolbar button sits above the canvas, and a node placed at the
     * button's own coordinates would appear off the top of the viewport — added,
     * counted, and invisible. A click that came from outside the canvas drops
     * its node in the middle of what the author is looking at instead.
     */
    const openPicker = useCallback((clientX, clientY, from = null) => {
        const box = area.current?.getBoundingClientRect();

        if (!box) {
            return;
        }

        const inside =
            clientX >= box.left && clientX <= box.right && clientY >= box.top && clientY <= box.bottom;

        const spawn = inside
            ? { x: clientX, y: clientY }
            : { x: box.left + box.width / 2, y: box.top + box.height / 2 };

        setPicker({
            at: {
                x: Math.min(Math.max(spawn.x - box.left, 8), Math.max(box.width - 272, 8)),
                y: Math.min(Math.max(spawn.y - box.top, 8), Math.max(box.height - 120, 8)),
            },
            client: spawn,
            from,
        });
    }, []);

    /**
     * Places a new node, and joins it to the arrow that opened the picker.
     *
     * An arrow dropped on empty space is a question — "and then what?" — and
     * answering it by creating a loose node the author then has to connect by
     * hand is answering half of it.
     */
    const addNode = useCallback(
        (type) => {
            const request = picker;

            setPicker(null);

            const position = positionOf(request?.client?.x || 0, request?.client?.y || 0);

            commit(({ nodes: currentNodes, edges: currentEdges }) => {
                const created = newNode(type, position, currentNodes);
                const nextNodes = [...currentNodes, created];
                const from = request?.from;

                const nextEdges = from
                    ? [
                          ...currentEdges.filter(
                              (edge) => !(edge.source === from.nodeId && edge.sourceHandle === from.handleId),
                          ),
                          {
                              id: edgeId(from.nodeId, from.handleId, created.id),
                              source: from.nodeId,
                              target: created.id,
                              sourceHandle: from.handleId,
                              targetHandle: IN_HANDLE,
                          },
                      ]
                    : currentEdges;

                return { nodes: nextNodes, edges: nextEdges };
            });
        },
        [commit, picker, positionOf],
    );

    const addNote = useCallback(() => {
        const box = area.current?.getBoundingClientRect();
        const position = positionOf((box?.left || 0) + 120, (box?.top || 0) + 120);

        commit(({ nodes: currentNodes, edges: currentEdges }) => ({
            nodes: [
                ...currentNodes,
                {
                    id: `note-${Date.now()}`,
                    type: 'note',
                    position,
                    data: { text: '' },
                },
            ],
            edges: currentEdges,
        }));
    }, [commit, positionOf]);

    const patchSelected = useCallback(
        (patch) => {
            commit(
                ({ nodes: currentNodes, edges: currentEdges }) => ({
                    nodes: currentNodes.map((node) =>
                        node.id === selectedId ? { ...node, data: { ...node.data, ...patch } } : node,
                    ),
                    edges: currentEdges,
                }),
                { coalesce: true },
            );
        },
        [commit, selectedId],
    );

    /**
     * Renames a node, everywhere at once.
     *
     * The key is the identity: transitions are recorded by it and the run
     * journal prints it. So the rename has to travel to every arrow in the same
     * change — a rename that moved the node but not its arrows would leave the
     * scenario pointing at a name nothing answers to.
     *
     * A blank or already-taken key is refused rather than applied, because both
     * would silently merge two nodes into one on save.
     */
    const renameSelected = useCallback(
        (key) => {
            const clean = key.trim();

            if (clean === '' || nodes.some((node) => node.id === clean)) {
                return;
            }

            commit(
                ({ nodes: currentNodes, edges: currentEdges }) => ({
                    nodes: currentNodes.map((node) =>
                        node.id === selectedId ? { ...node, id: clean, data: { ...node.data, key: clean } } : node,
                    ),
                    edges: currentEdges.map((edge) => ({
                        ...edge,
                        source: edge.source === selectedId ? clean : edge.source,
                        target: edge.target === selectedId ? clean : edge.target,
                        id:
                            edge.source === selectedId || edge.target === selectedId
                                ? edgeId(
                                      edge.source === selectedId ? clean : edge.source,
                                      edge.sourceHandle,
                                      edge.target === selectedId ? clean : edge.target,
                                  )
                                : edge.id,
                    })),
                }),
                { coalesce: true },
            );

            setSelectedId(clean);
        },
        [commit, nodes, selectedId],
    );

    const removeSelection = useCallback(() => {
        commit(({ nodes: currentNodes, edges: currentEdges }) => {
            const going = new Set(currentNodes.filter((node) => node.selected).map((node) => node.id));

            return {
                nodes: currentNodes.filter((node) => !going.has(node.id)),
                edges: currentEdges.filter(
                    (edge) => !edge.selected && !going.has(edge.source) && !going.has(edge.target),
                ),
            };
        });
    }, [commit]);

    const deleteNode = useCallback(
        (id) => {
            commit(({ nodes: currentNodes, edges: currentEdges }) => ({
                nodes: currentNodes.filter((node) => node.id !== id),
                edges: currentEdges.filter((edge) => edge.source !== id && edge.target !== id),
            }));

            setSelectedId(null);
        },
        [commit],
    );

    const autoArrange = useCallback(() => {
        commit(({ nodes: currentNodes, edges: currentEdges }) => ({
            nodes: arrange(currentNodes, currentEdges),
            edges: currentEdges,
        }));

        window.requestAnimationFrame(() => flow.fitView({ duration: 300 }));
    }, [commit, flow]);

    /**
     * Puts a recipe's nodes on the board and lays the whole thing out again.
     *
     * **Auto-arranged rather than placed.** The server sends the nodes without
     * coordinates on purpose: chosen there, they would land on top of whatever
     * the author already has, and the first thing anybody would do is drag six
     * boxes out from under six others.
     *
     * Nothing is saved. The nodes arrive as an ordinary unsaved change, marked
     * dirty like any edit, so an author who inserted the wrong one leaves the
     * page and loses exactly what they would expect to lose.
     */
    const useRecipe = useCallback(
        async (key) => {
            setBusy(true);
            setRecipesOpen(false);

            try {
                const recipe = await wire.recipe(key);

                if (!recipe || !Array.isArray(recipe.nodes) || recipe.nodes.length === 0) {
                    return;
                }

                commit(({ nodes: currentNodes, edges: currentEdges }) => {
                    const { nodes: merged, edges: mergedEdges } = insertRecipe(
                        recipe,
                        currentNodes,
                        currentEdges,
                    );

                    return { nodes: arrange(merged, mergedEdges), edges: mergedEdges };
                });

                window.requestAnimationFrame(() => flow.fitView({ duration: 300 }));
            } finally {
                setBusy(false);
            }
        },
        [commit, flow, wire],
    );

    /**
     * The draft as the server reads it.
     *
     * Groups are deliberately absent. The server only touches the canvas
     * furniture the payload mentions, and sending an empty list for something
     * this editor never edits would clear it on the first save.
     */
    const payload = useCallback(
        () => toPayload(current.current.nodes, current.current.edges, { viewport: flow.getViewport() }),
        [flow],
    );

    const run = useCallback(
        async (call, clean) => {
            setBusy(true);

            try {
                const answer = await call(payload());

                if (answer && Array.isArray(answer.issues)) {
                    applyIssues(answer.issues);
                }

                if (clean) {
                    setDirty(false);
                }
            } catch (error) {
                console.error('[chatbot] the server refused the graph', error);
            } finally {
                setBusy(false);
            }
        },
        [applyIssues, payload],
    );

    const save = useCallback(() => run(wire.save, true), [run, wire]);
    const check = useCallback(() => run(wire.check, false), [run, wire]);

    /**
     * One turn of the sandbox conversation.
     *
     * The draft is deliberately not sent. The engine answers from the saved
     * scenario, and pretending otherwise would report on a scenario no visitor
     * can reach — the panel says so whenever there are unsaved changes.
     */
    const sandboxRun = useCallback(
        async (call) => {
            setSandboxBusy(true);

            try {
                const answer = await call();

                if (answer) {
                    setSandbox(answer);
                    applyVisited(answer.visited);
                }
            } catch (error) {
                console.error('[chatbot] the sandbox refused the turn', error);
            } finally {
                setSandboxBusy(false);
            }
        },
        [applyVisited],
    );

    const say = useCallback((line) => sandboxRun(() => wire.say(line)), [sandboxRun, wire]);
    const resetSandbox = useCallback(() => sandboxRun(() => wire.reset()), [sandboxRun, wire]);

    const undo = useCallback(() => {
        const previous = past.current.pop();

        if (!previous) {
            return;
        }

        future.current.push(current.current);
        current.current = previous;
        setNodes(previous.nodes);
        setEdges(previous.edges);
        setDirty(true);
    }, []);

    const redo = useCallback(() => {
        const next = future.current.pop();

        if (!next) {
            return;
        }

        past.current.push(current.current);
        current.current = next;
        setNodes(next.nodes);
        setEdges(next.edges);
        setDirty(true);
    }, []);

    /*
     * Keyboard shortcuts, ignored while somebody is typing.
     *
     * This guard is the whole reason the canvas handles Delete itself: with the
     * properties panel open, a Backspace meant for a message box was deleting
     * the node the box belonged to.
     */
    useEffect(() => {
        function onKey(event) {
            const target = event.target;
            const typing =
                target instanceof HTMLElement &&
                (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

            if (typing) {
                return;
            }

            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
                event.preventDefault();

                if (event.shiftKey) {
                    redo();
                } else {
                    undo();
                }

                return;
            }

            if (event.key === 'Delete' || event.key === 'Backspace') {
                event.preventDefault();
                removeSelection();
            }
        }

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [redo, removeSelection, undo]);

    /*
     * Nothing is saved until Save is pressed, so leaving the page throws the
     * draft away. The browser only allows this warning when the person has
     * actually changed something, which is exactly when it is wanted.
     */
    useEffect(() => {
        function onLeave(event) {
            if (!dirty) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        }

        window.addEventListener('beforeunload', onLeave);

        return () => window.removeEventListener('beforeunload', onLeave);
    }, [dirty]);

    const blocking = issues.filter((issue) => issue.severity === 'blocking').length;
    const notices = issues.length - blocking;

    /*
     * A saved viewport is honoured only when it says something. A scenario that
     * has never been opened carries zeroes, and restoring those would drop an
     * author into the corner of an empty canvas with the whole graph off screen
     * — which reads as the scenario having lost its nodes.
     */
    const savedViewport = graph.viewport?.zoom ? graph.viewport : null;

    /*
     * An installation that registered none gets no button at all, rather than a
     * button that opens on an empty list — which reads as the feature being
     * broken rather than as nothing being offered.
     */
    const recipes = config.recipes || [];

    return (
        <div className="chatbot-graph__shell" ref={wrapper}>
            <div className="chatbot-graph__bar">
                <button
                    type="button"
                    onClick={(event) => openPicker(event.clientX, event.clientY)}
                    className="chatbot-graph__button"
                >
                    {text('add_step')}
                </button>
                <button type="button" onClick={addNote} className="chatbot-graph__button">
                    {text('add_note')}
                </button>
                {recipes.length > 0 ? (
                    <div className="chatbot-graph__recipes">
                        <button
                            type="button"
                            onClick={() => setRecipesOpen((open) => !open)}
                            disabled={busy}
                            className={`chatbot-graph__button ${recipesOpen ? 'is-active' : ''}`}
                        >
                            {text('recipes')}
                        </button>

                        {recipesOpen ? (
                            <div className="chatbot-graph__recipe-list">
                                {recipes.map((recipe) => (
                                    <button
                                        key={recipe.key}
                                        type="button"
                                        onClick={() => useRecipe(recipe.key)}
                                        className="chatbot-graph__recipe"
                                    >
                                        <span className="chatbot-graph__recipe-name">{recipe.label}</span>
                                        <span className="chatbot-graph__recipe-note">{recipe.description}</span>
                                    </button>
                                ))}
                            </div>
                        ) : null}
                    </div>
                ) : null}

                <button type="button" onClick={autoArrange} className="chatbot-graph__button">
                    {text('auto_arrange')}
                </button>

                <span className="chatbot-graph__spacer" />

                <span className={`chatbot-graph__count ${blocking > 0 ? 'is-blocking' : ''}`}>
                    {text('counts', { blocking, notices })}
                </span>

                {dirty ? <span className="chatbot-graph__count is-dirty">{text('unsaved')}</span> : null}

                <button
                    type="button"
                    onClick={() => setTrying((open) => !open)}
                    className={`chatbot-graph__button ${trying ? 'is-active' : ''}`}
                >
                    {text('try_it')}
                </button>

                <button type="button" onClick={check} disabled={busy} className="chatbot-graph__button">
                    {text('check')}
                </button>
                <button
                    type="button"
                    onClick={save}
                    disabled={busy}
                    className="chatbot-graph__button is-primary"
                >
                    {text('save')}
                </button>
            </div>

            <div className="chatbot-graph__body">
                <div className="chatbot-graph__canvas-area" ref={area}>
                    <ReactFlow
                        nodes={nodes}
                        edges={drawnEdges}
                        nodeTypes={nodeTypes}
                        onNodesChange={onNodesChange}
                        onEdgesChange={onEdgesChange}
                        onConnect={onConnect}
                        onNodeDragStart={() => remember()}
                        onNodeDragStop={() => setDirty(true)}
                        onSelectionChange={({ nodes: chosen }) => setSelectedId(chosen?.[0]?.id ?? null)}
                        onPaneContextMenu={(event) => {
                            event.preventDefault();
                            openPicker(event.clientX, event.clientY);
                        }}
                        onConnectEnd={(event, state) => {
                            if (state?.isValid || !state?.fromNode) {
                                return;
                            }

                            const point = event.changedTouches ? event.changedTouches[0] : event;

                            openPicker(point.clientX, point.clientY, {
                                nodeId: state.fromNode.id,
                                handleId: state.fromHandle?.id,
                            });
                        }}
                        deleteKeyCode={null}
                        defaultViewport={savedViewport || undefined}
                        fitView={!savedViewport}
                    >
                        <Background gap={20} />
                        <MiniMap
                            pannable
                            zoomable
                            nodeColor={(node) => (node.type === 'step' ? colorOf(node.data.type) : '#facc15')}
                        />
                        <Controls />
                    </ReactFlow>

                    {picker ? (
                        <StepPicker
                            at={picker.at}
                            suggestedFor={
                                picker.from
                                    ? nodes.find((node) => node.id === picker.from.nodeId)?.data.type
                                    : selected?.data.type
                            }
                            onPick={addNode}
                            onClose={() => setPicker(null)}
                        />
                    ) : null}
                </div>

                <SideForm
                    node={selected}
                    config={config}
                    onChange={patchSelected}
                    onRename={renameSelected}
                    onDelete={() => deleteNode(selectedId)}
                />

                {trying ? (
                    <Sandbox
                        sandbox={sandbox}
                        dirty={dirty}
                        busy={sandboxBusy}
                        onSay={say}
                        onReset={resetSandbox}
                    />
                ) : null}
            </div>
        </div>
    );
}

export default function Editor({ config, graph, sandbox, wire }) {
    return (
        <ReactFlowProvider>
            <Canvas config={config} graph={graph} sandbox={sandbox} wire={wire} />
        </ReactFlowProvider>
    );
}
