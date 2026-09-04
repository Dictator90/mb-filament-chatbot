import { memo, useEffect } from 'react';
import { Handle, Position, useUpdateNodeInternals } from '@xyflow/react';

import { IN_HANDLE, outHandle } from './model';
import { colorOf, describe, fieldsOf, labelOf, outputLabels, speaks } from './types';

/*
 * One node on the canvas.
 *
 * Everything drawn here is asked of the type dictionary rather than decided by
 * a switch on the kind. A node registered by the host application therefore
 * arrives with its colour, its label and the right number of sockets without a
 * line of JavaScript being written for it — which is the whole reason the
 * dictionary is sent from the server in the first place.
 */

const SUMMARY_LIMIT = 80;

function shorten(text) {
    const flat = String(text).replace(/\s+/g, ' ').trim();

    return flat.length > SUMMARY_LIMIT ? `${flat.slice(0, SUMMARY_LIMIT - 1)}…` : flat;
}

/**
 * The second line of the box: what this node actually does, in a few words.
 *
 * A canvas of twelve identical boxes labelled "Say" and "Condition" tells an
 * author nothing, and opening each one to remember which is which is how a
 * scenario stops being readable at the size where reading it matters. The
 * spoken text comes first because that is what the visitor sees; for a silent
 * node the first filled setting stands in for it.
 */
function summaryOf(data) {
    if (speaks(data.type) && data.body) {
        return shorten(data.body);
    }

    const config = data.config || {};

    for (const field of fieldsOf(data.type)) {
        const value = config[field.name];

        if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
            continue;
        }

        if (typeof value === 'object') {
            continue;
        }

        return shorten(`${field.label || field.name}: ${value}`);
    }

    if (data.body) {
        return shorten(data.body);
    }

    return '';
}

/**
 * A stand-in for the node's icon.
 *
 * The icon a step declares is a Heroicon name, and the bundle has no way to
 * turn a name into a drawing for a node it has never heard of. A table of the
 * package's own icons would look finished and then show nothing at all for the
 * host's steps — the exact nodes whose author most needs to recognise them. A
 * letter over the node's own colour treats every kind alike.
 */
function Glyph({ type, color }) {
    const letter = (labelOf(type) || type || '?').trim().charAt(0).toUpperCase();

    return (
        <span className="chatbot-graph__glyph" style={{ backgroundColor: color }}>
            {letter}
        </span>
    );
}

function StepNode({ id, data, selected }) {
    const updateNodeInternals = useUpdateNodeInternals();
    const described = describe(data.type);
    const color = colorOf(data.type);
    const slots = outputLabels(data.type, data.options);
    const problem = data.problem;
    const summary = summaryOf(data);

    const classes = [
        'chatbot-graph__node',
        selected ? 'is-selected' : '',
        described ? '' : 'is-foreign',
        problem ? `has-problem is-${problem.severity}` : '',
    ]
        .filter(Boolean)
        .join(' ');

    /*
     * An unrecognised kind keeps one socket, and that socket cannot be
     * connected to anything new.
     *
     * Its outgoing arrow has to stay visible: the scenario was drawn while some
     * package was installed, and an arrow the canvas refuses to draw is an
     * arrow the next save deletes. Letting the author draw a *new* one would be
     * the opposite mistake — nothing here knows what that socket means, so the
     * bot would not follow it.
     */
    const sockets = described ? slots : ['?'];

    /*
     * The canvas keeps its own register of sockets, and adding a branch does not
     * update it on its own.
     *
     * Without this the new socket is drawn and dead: nothing can be dragged out
     * of it, and an arrow already pointing at the position it took over is drawn
     * from the old coordinates. Both look like the canvas ignoring the branch
     * that was just added.
     */
    useEffect(() => {
        updateNodeInternals(id);
    }, [id, sockets.length, updateNodeInternals]);

    return (
        <div className={classes} style={{ '--chatbot-node-color': color }}>
            <Handle type="target" position={Position.Top} id={IN_HANDLE} className="chatbot-graph__handle" />

            <div className="chatbot-graph__node-head">
                <Glyph type={data.type} color={color} />

                <div className="chatbot-graph__node-title">
                    <span className="chatbot-graph__node-kind">{described ? described.label : 'Unknown kind'}</span>
                    <span className="chatbot-graph__node-key">{data.key}</span>
                </div>

                {problem ? (
                    <span
                        className={`chatbot-graph__badge is-${problem.severity}`}
                        title={problem.message}
                        aria-label={problem.message}
                    >
                        {problem.severity === 'blocking' ? '!' : '?'}
                    </span>
                ) : null}
            </div>

            {described ? null : (
                <p className="chatbot-graph__node-foreign">
                    Nothing in this installation provides <code>{data.type}</code>. The node is kept as it is.
                </p>
            )}

            {summary ? <p className="chatbot-graph__node-summary">{summary}</p> : null}

            {sockets.length > 0 ? (
                <div className="chatbot-graph__slots">
                    {sockets.map((label, index) => (
                        <div className="chatbot-graph__slot" key={`${label}-${index}`} title={label}>
                            <span className="chatbot-graph__slot-label">{label}</span>
                            <Handle
                                type="source"
                                position={Position.Bottom}
                                id={outHandle(index)}
                                isConnectable={Boolean(described)}
                                className="chatbot-graph__handle"
                                style={{ left: '50%', bottom: -5 }}
                            />
                        </div>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

export default memo(StepNode);
