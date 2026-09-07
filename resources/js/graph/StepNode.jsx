import { memo, useEffect } from 'react';
import { Handle, Position, useUpdateNodeInternals } from '@xyflow/react';

import { IN_HANDLE, outHandle } from './model';
import { colorOf, describe, fieldsOf, iconSvgOf, labelOf, outputLabels, speaks, text } from './types';

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
 * The node's icon, drawn on the server.
 *
 * A step declares a Heroicon name, and this bundle cannot turn a name into a
 * picture — least of all for a kind the host registered and it has never heard
 * of. So it no longer tries: the icon set is installed on the server, the name
 * is resolved there, and the markup arrives already drawn in `icon_svg`. Every
 * kind gets the icon it declared, the host's kinds included.
 *
 * The letter stays for the two cases that yield no drawing — a name nothing has
 * installed, and a kind this installation no longer provides at all. Both still
 * need something on the node, and a letter over the node's own colour is it.
 *
 * Inserted as HTML because it is markup, rendered on the server from a name
 * written in PHP the host already runs. Nothing in it comes from a conversation,
 * a visitor, or the database.
 */
function Glyph({ type, color, icon }) {
    const letter = (labelOf(type) || type || '?').trim().charAt(0).toUpperCase();

    return (
        <span className="chatbot-graph__glyph" style={{ backgroundColor: color }}>
            {icon ? <span dangerouslySetInnerHTML={{ __html: icon }} /> : letter}
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
        data.visit ? 'is-visited' : '',
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
                <Glyph type={data.type} color={color} icon={iconSvgOf(data.type)} />

                <div className="chatbot-graph__node-title">
                    <span className="chatbot-graph__node-kind">
                        {described ? described.label : text('unknown_kind')}
                    </span>
                    <span className="chatbot-graph__node-key">{data.key}</span>
                </div>

                {/*
                    Where this node came in the sandbox conversation, not merely
                    that it was reached. A scenario that loops walks the same node
                    twice, and "it was here" is a different fact from "it was here
                    first" — which is the fact an author is checking.
                */}
                {data.visit ? (
                    <span
                        className="chatbot-graph__visit"
                        title={text('sandbox_step', { number: data.visit })}
                    >
                        {data.visit}
                    </span>
                ) : null}

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
