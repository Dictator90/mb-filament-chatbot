import { useState } from 'react';

import { describe, fieldsOf, labelOf, speaks } from './types';

/*
 * The properties of the selected node, drawn from the descriptors the server
 * sent — and from nothing else.
 *
 * This is the file that decides whether a host can add a node to the bot. Every
 * setting of every kind is drawn by the same code here, off the `fields` list
 * the step type declares in PHP, so a node written by the host application
 * needs no JavaScript at all. The moment this file grows a branch of the form
 * "if the kind is X, draw Y", the package owns that node's panel and a host can
 * only add nodes by forking the bundle.
 *
 * The one place two built-in kinds are named by hand is the branch editor, and
 * it says why there.
 */

/**
 * Operators that answer their own question.
 *
 * "Is it filled" takes no value, and a value box next to it is how an author
 * comes to believe they set a threshold nothing reads. The list mirrors
 * ConditionKind::operatorNeedsValue() on the server; the condition descriptors
 * carry the operators a field allows but not this, and the editor has to decide
 * it while the person types, with no round trip.
 */
const VALUELESS_OPERATORS = ['yes', 'no', 'filled', 'blank'];

const CUSTOM_VARIABLE = '__custom__';

function humanize(text) {
    return String(text || '')
        .replace(/[_-]+/g, ' ')
        .replace(/^./, (character) => character.toUpperCase());
}

function moved(list, from, to) {
    if (to < 0 || to >= list.length) {
        return list;
    }

    const next = [...list];
    const [item] = next.splice(from, 1);

    next.splice(to, 0, item);

    return next;
}

function Labelled({ field, children }) {
    return (
        <label className="chatbot-graph__field">
            <span className="chatbot-graph__field-label">
                {field.label || field.name}
                {field.required ? <em className="chatbot-graph__required">required</em> : null}
            </span>
            {children}
            {field.hint ? <span className="chatbot-graph__hint">{field.hint}</span> : null}
        </label>
    );
}

function Reorder({ index, length, onMove, onRemove }) {
    return (
        <div className="chatbot-graph__row-tools">
            <button type="button" onClick={() => onMove(index, index - 1)} disabled={index === 0} title="Move up">
                ↑
            </button>
            <button
                type="button"
                onClick={() => onMove(index, index + 1)}
                disabled={index === length - 1}
                title="Move down"
            >
                ↓
            </button>
            <button type="button" onClick={() => onRemove(index)} title="Remove">
                ×
            </button>
        </div>
    );
}

function SelectWidget({ field, value, onChange }) {
    const options = field.options || {};

    /*
     * A multiple choice is a column of checkboxes rather than a multi-select.
     * A <select multiple> needs ctrl-click to add a second value and gives no
     * hint that it does, so authors picked one option believing they had picked
     * two — and the scenario ran with one.
     */
    if (field.multiple) {
        const chosen = Array.isArray(value) ? value : [];

        return (
            <div className="chatbot-graph__checks">
                {Object.entries(options).map(([key, label]) => (
                    <label key={key} className="chatbot-graph__check">
                        <input
                            type="checkbox"
                            checked={chosen.includes(key)}
                            onChange={(event) =>
                                onChange(
                                    event.target.checked
                                        ? [...chosen, key]
                                        : chosen.filter((item) => item !== key),
                                )
                            }
                        />
                        <span>{label}</span>
                    </label>
                ))}
            </div>
        );
    }

    return (
        <select value={value ?? ''} onChange={(event) => onChange(event.target.value || null)}>
            <option value="">—</option>
            {Object.entries(options).map(([key, label]) => (
                <option key={key} value={key}>
                    {label}
                </option>
            ))}
        </select>
    );
}

function ListWidget({ value, onChange, placeholder }) {
    const items = Array.isArray(value) ? value : [];

    return (
        <div className="chatbot-graph__list">
            {items.map((item, index) => (
                <div className="chatbot-graph__list-row" key={index}>
                    <input
                        type="text"
                        value={item ?? ''}
                        placeholder={placeholder}
                        onChange={(event) =>
                            onChange(items.map((each, at) => (at === index ? event.target.value : each)))
                        }
                    />
                    <Reorder
                        index={index}
                        length={items.length}
                        onMove={(from, to) => onChange(moved(items, from, to))}
                        onRemove={(at) => onChange(items.filter((each, position) => position !== at))}
                    />
                </div>
            ))}

            <button type="button" className="chatbot-graph__add" onClick={() => onChange([...items, ''])}>
                Add
            </button>
        </div>
    );
}

/**
 * Key and value pairs, held as rows while they are edited.
 *
 * The saved shape is an object, and editing an object directly cannot work: the
 * moment a key is cleared to be retyped, the pair collides with any other empty
 * key and both disappear under the author's hands. Rows keep their identity and
 * their order, and the object is rebuilt from them.
 */
function KeyValueWidget({ value, onChange }) {
    const [rows, setRows] = useState(() => Object.entries(value || {}).map(([key, item]) => ({ key, value: item })));

    function publish(next) {
        setRows(next);

        const object = {};

        next.forEach((row) => {
            if (String(row.key).trim() !== '') {
                object[row.key] = row.value;
            }
        });

        onChange(object);
    }

    return (
        <div className="chatbot-graph__pairs">
            {rows.map((row, index) => (
                <div className="chatbot-graph__pair" key={index}>
                    <input
                        type="text"
                        placeholder="key"
                        value={row.key}
                        onChange={(event) =>
                            publish(rows.map((each, at) => (at === index ? { ...each, key: event.target.value } : each)))
                        }
                    />
                    <input
                        type="text"
                        placeholder="value"
                        value={row.value ?? ''}
                        onChange={(event) =>
                            publish(
                                rows.map((each, at) => (at === index ? { ...each, value: event.target.value } : each)),
                            )
                        }
                    />
                    <button
                        type="button"
                        title="Remove"
                        onClick={() => publish(rows.filter((each, at) => at !== index))}
                    >
                        ×
                    </button>
                </div>
            ))}

            <button
                type="button"
                className="chatbot-graph__add"
                onClick={() => publish([...rows, { key: '', value: '' }])}
            >
                Add
            </button>
        </div>
    );
}

/**
 * A variable, chosen from the ones the scenario is known to collect, or typed.
 *
 * Typing has to stay possible: variables also arrive from the host's own steps
 * and from channels the editor cannot enumerate, and a picker that refused
 * anything unlisted would make those unreachable from a scenario.
 */
function VariableWidget({ value, variables, onChange }) {
    /*
     * The server sends variables grouped by the source that fills them —
     * `[{ key, label, available_after, fields: [{ name, label }] }]` — and this
     * read them as a flat `{ name: label }` map. `Object.entries` over an array
     * yields the group objects themselves, so React was handed one as a child
     * and the whole canvas went blank with "Objects are not valid as a React
     * child". It happened the first time anybody opened a condition node, which
     * is the only node that shows this widget — the shape had been wrong since
     * the canvas was written and nothing had asked for it.
     *
     * Grouped is the right shape, and worth drawing as groups: which source
     * fills a variable is what tells an author whether it will hold anything by
     * the time this node runs.
     */
    const groups = Array.isArray(variables) ? variables : [];
    const known = groups.flatMap((group) => group.fields || []);
    const listed = known.some((field) => field.name === (value ?? ''));
    const [custom, setCustom] = useState(Boolean(value) && !listed);

    return (
        <div className="chatbot-graph__variable">
            <select
                value={custom ? CUSTOM_VARIABLE : (value ?? '')}
                onChange={(event) => {
                    if (event.target.value === CUSTOM_VARIABLE) {
                        setCustom(true);

                        return;
                    }

                    setCustom(false);
                    onChange(event.target.value || null);
                }}
            >
                <option value="">—</option>
                {groups.map((group) => (
                    <optgroup key={group.key} label={group.label}>
                        {(group.fields || []).map((field) => (
                            <option key={field.name} value={field.name}>
                                {field.label} ({field.name})
                            </option>
                        ))}
                    </optgroup>
                ))}
                <option value={CUSTOM_VARIABLE}>Another name…</option>
            </select>

            {custom ? (
                <input
                    type="text"
                    placeholder="variable name"
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value || null)}
                />
            ) : null}
        </div>
    );
}

function LookupWidget({ value, items, onChange }) {
    return (
        <select value={value ?? ''} onChange={(event) => onChange(event.target.value || null)}>
            <option value="">—</option>
            {items.map((item) => (
                <option key={item.value} value={item.value}>
                    {item.label}
                </option>
            ))}
        </select>
    );
}

function lookupItems(config, type) {
    if (type === 'scenario') {
        return (config.scenarios || []).map((item) => ({ value: item.slug, label: item.name || item.slug }));
    }

    if (type === 'data_resource') {
        return (config.dataResources || []).map((item) => ({ value: item.key, label: item.label || item.key }));
    }

    return (config.capabilities || []).map((item) => ({ value: item.key, label: item.label || item.key }));
}

function FieldWidget({ field, value, config, onChange }) {
    switch (field.type) {
        case 'textarea':
            return (
                <textarea
                    rows={field.rows || 4}
                    placeholder={field.placeholder || ''}
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value)}
                />
            );

        case 'number':
            return (
                <input
                    type="number"
                    min={field.min}
                    max={field.max}
                    placeholder={field.placeholder || ''}
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))}
                />
            );

        case 'toggle':
            return (
                <input type="checkbox" checked={Boolean(value)} onChange={(event) => onChange(event.target.checked)} />
            );

        case 'select':
            return <SelectWidget field={field} value={value} onChange={onChange} />;

        case 'list':
            return <ListWidget value={value} onChange={onChange} placeholder={field.placeholder || ''} />;

        case 'key_value':
            return <KeyValueWidget value={value} onChange={onChange} />;

        case 'variable':
            return <VariableWidget value={value} variables={config.variables} onChange={onChange} />;

        case 'scenario':
        case 'data_resource':
        case 'capability':
            return <LookupWidget value={value} items={lookupItems(config, field.type)} onChange={onChange} />;

        default:
            return (
                <input
                    type="text"
                    placeholder={field.placeholder || ''}
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
    }
}

/**
 * Whether a field applies to the settings as they stand.
 *
 * The comparison is loose on purpose: a descriptor written in PHP may say the
 * field appears when `attempts` is 2, and the same number arrives from a text
 * box as "2". A strict comparison would hide a field that should be visible,
 * and the author would be looking for a setting that exists.
 */
function visible(field, config) {
    const rule = field.visible_when;

    if (!rule) {
        return true;
    }

    const current = config[rule.field];

    return (rule.values || []).some((candidate) => String(candidate) === String(current ?? ''));
}

/**
 * The words a keyword branch matches on.
 */
function WordsEditor({ option, onChange }) {
    return (
        <div className="chatbot-graph__branch-detail">
            <span className="chatbot-graph__field-label">Words</span>
            <ListWidget
                value={option.words}
                placeholder="word or phrase"
                onChange={(words) => onChange({ ...option, words })}
            />
        </div>
    );
}

/**
 * The comparison a condition branch is taken on.
 *
 * The value box disappears for operators that answer their own question, and
 * the name box appears only for fields that are really a family — "a collected
 * variable" is not one condition but as many as there are variables.
 */
function ConditionEditor({ option, config, onChange }) {
    const when = option.when || {};
    const conditions = config.conditions || [];
    const chosen = conditions.find((condition) => condition.value === when.field) || null;
    const operators = chosen?.operators || [];
    const needsValue = when.operator ? !VALUELESS_OPERATORS.includes(when.operator) : false;

    function set(patch) {
        onChange({ ...option, when: { ...when, ...patch } });
    }

    return (
        <div className="chatbot-graph__branch-detail">
            <span className="chatbot-graph__field-label">When</span>

            <select
                value={when.field ?? ''}
                onChange={(event) => {
                    const next = conditions.find((condition) => condition.value === event.target.value) || null;

                    /*
                     * Changing the field resets the operator. Operators belong
                     * to the kind of field, and one carried over from the
                     * previous choice — "contains" left on a yes/no field —
                     * evaluates to false forever, which reads on the canvas
                     * exactly like a branch that simply has not matched yet.
                     */
                    set({
                        field: event.target.value || null,
                        operator: next?.operators?.[0] ?? null,
                        name: next?.needs_name ? (when.name ?? '') : null,
                        value: null,
                    });
                }}
            >
                <option value="">—</option>
                {conditions.map((condition) => (
                    <option key={condition.value} value={condition.value}>
                        {condition.label}
                    </option>
                ))}
            </select>

            {chosen?.needs_name ? (
                <VariableWidget
                    value={when.name}
                    variables={config.variables}
                    onChange={(name) => set({ name })}
                />
            ) : null}

            <select
                value={when.operator ?? ''}
                onChange={(event) => set({ operator: event.target.value || null })}
                disabled={!chosen}
            >
                <option value="">—</option>
                {operators.map((operator) => (
                    <option key={operator} value={operator}>
                        {humanize(operator)}
                    </option>
                ))}
            </select>

            {/*
             * "One of" is the one operator that compares against several
             * values at once, so it gets a box that can hold several. Offered
             * as a single-choice list — the shape the other operators want — an
             * author can only ever name one of them, and a branch meant for
             * three cases quietly answers for one.
             */}
            {needsValue ? (
                chosen?.options ? (
                    when.operator === 'one_of' ? (
                        <SelectWidget
                            field={{ options: chosen.options, multiple: true }}
                            value={Array.isArray(when.value) ? when.value : when.value ? [when.value] : []}
                            onChange={(value) => set({ value })}
                        />
                    ) : (
                        <select
                            value={when.value ?? ''}
                            onChange={(event) => set({ value: event.target.value || null })}
                        >
                            <option value="">—</option>
                            {Object.entries(chosen.options).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    )
                ) : (
                    <input
                        type={chosen?.kind === 'number' && when.operator !== 'one_of' ? 'number' : 'text'}
                        placeholder={
                            when.operator === 'one_of' ? 'value, value, value' : chosen?.hint || 'value'
                        }
                        value={Array.isArray(when.value) ? when.value.join(', ') : (when.value ?? '')}
                        onChange={(event) => set({ value: event.target.value })}
                    />
                )
            ) : null}
        </div>
    );
}

/**
 * What a branch of this kind of node carries besides its label.
 *
 * Two built-in kinds are named here, and this is the only place in the panel
 * where any kind is. A branch is a label plus a destination for every kind that
 * has branches; these two also carry the thing that decides which branch is
 * taken, and the field descriptors have no word for "a setting that belongs to
 * one option". A step type may say so itself through `option_shape`, and that
 * wins — the names below are the fallback for the package's own two, not a
 * rule about kinds in general.
 */
function branchShape(type) {
    const described = describe(type);

    if (described?.option_shape) {
        return described.option_shape;
    }

    if (type === 'keyword') {
        return 'words';
    }

    if (type === 'condition') {
        return 'when';
    }

    return 'label';
}

/**
 * The branches of the node, which are also its output sockets.
 *
 * Adding a row grows the node by one socket and removing one takes a socket
 * away, which is why the canvas drops the arrows that were hanging off it: an
 * arrow with no socket is invisible on screen and perfectly real in the saved
 * scenario.
 */
function BranchEditor({ node, config, onChange }) {
    const options = Array.isArray(node.data.options) ? node.data.options : [];
    const shape = branchShape(node.data.type);

    function publish(next) {
        onChange({ options: next });
    }

    function update(index, option) {
        publish(options.map((each, at) => (at === index ? option : each)));
    }

    return (
        <section className="chatbot-graph__section">
            <h3 className="chatbot-graph__section-title">Branches</h3>

            {options.length === 0 ? (
                <p className="chatbot-graph__empty">This node has no branches yet, so it has nowhere to go.</p>
            ) : null}

            {options.map((option, index) => (
                <div className="chatbot-graph__branch" key={index}>
                    <div className="chatbot-graph__branch-head">
                        <input
                            type="text"
                            placeholder="Branch label"
                            value={option.label ?? ''}
                            onChange={(event) => update(index, { ...option, label: event.target.value })}
                        />
                        <Reorder
                            index={index}
                            length={options.length}
                            onMove={(from, to) => publish(moved(options, from, to))}
                            onRemove={(at) => publish(options.filter((each, position) => position !== at))}
                        />
                    </div>

                    {shape === 'words' ? (
                        <WordsEditor option={option} onChange={(next) => update(index, next)} />
                    ) : null}

                    {shape === 'when' ? (
                        <ConditionEditor
                            option={option}
                            config={config}
                            onChange={(next) => update(index, next)}
                        />
                    ) : null}
                </div>
            ))}

            <button
                type="button"
                className="chatbot-graph__add"
                onClick={() => publish([...options, { label: `Branch ${options.length + 1}` }])}
            >
                Add branch
            </button>
        </section>
    );
}

export default function SideForm({ node, config, onChange, onRename, onDelete }) {
    if (!node) {
        return (
            <aside className="chatbot-graph__side is-empty">
                <p className="chatbot-graph__empty">Select a node to see what it does.</p>
            </aside>
        );
    }

    const { data } = node;
    const described = describe(data.type);
    const settings = data.config || {};
    const branching = described?.outputs === 'options' || described?.outputs === 'options_else';

    function setSetting(name, value) {
        onChange({ config: { ...settings, [name]: value } });
    }

    return (
        <aside className="chatbot-graph__side">
            <header className="chatbot-graph__side-head">
                <div>
                    <p className="chatbot-graph__side-kind">{described ? described.label : labelOf(data.type)}</p>
                    <input
                        className="chatbot-graph__key-input"
                        type="text"
                        value={data.key}
                        title="The key this node is known by, in transitions and in the run journal"
                        onChange={(event) => onRename(event.target.value)}
                    />
                </div>

                <button type="button" className="chatbot-graph__danger" onClick={onDelete}>
                    Delete
                </button>
            </header>

            {described ? null : (
                <p className="chatbot-graph__warning">
                    Nothing in this installation provides <code>{data.type}</code>. Its settings are kept untouched and
                    cannot be edited here.
                </p>
            )}

            {data.problem ? (
                <p className={`chatbot-graph__warning is-${data.problem.severity}`}>{data.problem.message}</p>
            ) : null}

            {speaks(data.type) ? (
                <section className="chatbot-graph__section">
                    <label className="chatbot-graph__field">
                        <span className="chatbot-graph__field-label">Message</span>
                        <textarea
                            rows={4}
                            value={data.body || ''}
                            placeholder="What the bot says here"
                            onChange={(event) => onChange({ body: event.target.value })}
                        />
                    </label>
                </section>
            ) : null}

            {fieldsOf(data.type).length > 0 ? (
                <section className="chatbot-graph__section">
                    {fieldsOf(data.type)
                        .filter((field) => visible(field, settings))
                        .map((field) => (
                            <Labelled field={field} key={field.name}>
                                <FieldWidget
                                    key={`${node.id}:${field.name}`}
                                    field={field}
                                    config={config}
                                    value={settings[field.name] ?? field.default ?? null}
                                    onChange={(value) => setSetting(field.name, value)}
                                />
                            </Labelled>
                        ))}
                </section>
            ) : null}

            {branching ? <BranchEditor node={node} config={config} onChange={onChange} /> : null}
        </aside>
    );
}
