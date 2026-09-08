<?php

declare(strict_types=1);

/**
 * Everything the scenario canvas says: node names, their settings, the exits
 * drawn on them, and the problems reported while a scenario is being drawn.
 *
 * Keyed by the node kind's own key rather than by a class name, because that
 * key is the stable identifier — a saved graph refers to it, and a host that
 * replaces a built-in node keeps the key and inherits its wording.
 */
return [

    /**
     * The three things a conversation itself remembers about a person.
     *
     * Shared rather than repeated under each node that can write them, because
     * they name the same columns wherever they are offered — and two spellings
     * of "phone" on two nodes is the kind of difference nobody notices until
     * somebody reads both screens in one sitting.
     */
    'conversation_fields' => [
        'name' => 'Their name',
        'phone' => 'Their phone number',
        'email' => 'Their email address',
    ],

    'steps' => [

        'start' => [
            'label' => 'Start',
            'fields' => [
                'trigger' => [
                    'label' => 'Starts on',
                    'hint' => 'Exactly one scenario per bot should be the fallback, or two of them will race for the same message.',
                    'options' => [
                        'greeting' => 'A conversation opening',
                        'keyword' => 'One of these words',
                        'fallback' => 'Anything nothing else answered',
                        'call' => 'Being called from another scenario',
                    ],
                ],
                'keywords' => [
                    'label' => 'Words',
                    'hint' => 'Matched inside the message, on whole words.',
                ],
                'channels' => [
                    'label' => 'Only on these channels',
                    'hint' => 'Leave empty for every channel this bot answers on.',
                ],
                'audience' => [
                    'label' => 'Only for',
                    'options' => [
                        'any' => 'Anyone',
                        'known' => 'Visitors we have identified',
                        'anonymous' => 'Visitors we have not identified',
                    ],
                ],
                'familiarity' => [
                    'label' => 'Only when',
                    'options' => [
                        'any' => 'Every conversation',
                        'new' => 'This is their first conversation',
                        'returning' => 'They have written before',
                    ],
                ],
            ],
        ],

        'say' => [
            'label' => 'Say',
        ],

        'ask' => [
            'label' => 'Ask',
            'retry' => 'Sorry, I did not catch that. Please pick one of these:',
            'fields' => [
                'retry' => [
                    'label' => 'When the answer matches nothing',
                    'hint' => 'Sent once with the options listed again. After that the fallback exit takes over, if there is one.',
                ],
            ],
        ],

        'collect' => [
            'label' => 'Collect an answer',
            'fields' => [
                'slot' => ['label' => 'What to ask for'],
                'into' => [
                    'label' => 'Remember as',
                    'hint' => 'Leave empty to store it under the slot name.',
                ],
                'attempts' => ['label' => 'Attempts before giving up'],
                'remember_as' => [
                    'label' => 'Also remember on the conversation',
                    'hint' => 'Writes the answer onto the thread itself, so the desk shows a person instead of a number. Never overwrites what the channel already knew.',
                    'none' => 'Only as a variable',
                ],
            ],
            'issues' => [
                'no_slot' => 'This node does not say what it asks for.',
            ],
        ],

        'form' => [
            'label' => 'Form',
            'exits' => ['collected', 'could not'],
            'fields' => [
                'slots' => ['label' => 'What to collect'],
                'attempts' => ['label' => 'Attempts per question'],
            ],
            'issues' => [
                'nothing_collected' => 'This form does not collect anything.',
            ],
        ],

        'keyword' => [
            'label' => 'Split on words',
        ],

        'condition' => [
            'label' => 'Condition',
            'issues' => [
                'branch_without_condition' => 'Branch ":branch" has no condition and can never be taken.',
                'branch_unusable' => 'Branch ":branch" is not described well enough to ever match.',
            ],
        ],

        'set' => [
            'label' => 'Remember',
            'fields' => [
                'name' => [
                    'label' => 'Name',
                    'placeholder' => 'interest',
                    'hint' => 'The name later steps and message templates read it back by.',
                ],
                'value' => [
                    'label' => 'Value',
                    'placeholder' => 'tour',
                    'hint' => 'May contain {{other_name}}, substituted when the node runs.',
                ],
            ],
            'issues' => [
                'no_name' => 'This node has no name to remember the value under, so it would do nothing at all.',
            ],
        ],

        'delay' => [
            'label' => 'Pause',
            'fields' => [
                'seconds' => [
                    'label' => 'Seconds',
                    'hint' => 'A second or two reads as typing. Longer pauses read as the bot having stopped.',
                ],
            ],
        ],

        'jump' => [
            'label' => 'Go to scenario',
            'fields' => [
                'scenario' => [
                    'label' => 'Continue in',
                    'hint' => 'The conversation carries on there and never returns to this scenario.',
                ],
            ],
            'issues' => [
                'no_scenario' => 'This node names no scenario, so every conversation reaching it goes to a person instead.',
            ],
        ],

        'call' => [
            'label' => 'Call a scenario',
            'exits' => ['returned', 'could not'],
            'fields' => [
                'scenario' => ['label' => 'Scenario to call'],
                'into' => [
                    'label' => 'Remember the answer as',
                    'hint' => 'Leave empty to ignore what it returns.',
                ],
            ],
            'issues' => [
                'no_scenario' => 'This node does not say which scenario to call.',
            ],
        ],

        'result' => [
            'label' => 'Return a result',
            'fields' => [
                'value' => [
                    'label' => 'Value to return',
                    'hint' => 'Supports {{variables}}. Leave empty to return nothing.',
                ],
            ],
        ],

        'answer' => [
            'label' => 'Answer from the knowledge base',
            'exits' => ['answered', 'do not know'],
            'fields' => [
                'category' => [
                    'label' => 'Only this section of the knowledge base',
                    'hint' => 'Leave empty to search every topic. Narrowing helps when one scenario is about one subject.',
                ],
                'fallback' => [
                    'label' => 'When the topic has no text',
                    'hint' => 'Sent when a topic matches but its answer field is empty. Left empty, the node takes its second exit instead.',
                ],
            ],
        ],

        'ai' => [
            'label' => 'Answer with a model',
            'exits' => ['answered', 'could not'],
            'fields' => [
                'provider' => [
                    'label' => 'Model',
                    'default_option' => 'The one this installation is set to',
                    'hint' => 'Leave as it is unless this particular answer needs a different provider.',
                ],
                'instruction' => [
                    'label' => 'How to answer',
                    'hint' => 'The model is told this before it sees the question. It only ever answers from the material found for the message.',
                ],
                'candidates' => [
                    'label' => 'How many articles to offer the model',
                    'hint' => 'More material means a better chance of an answer and a larger, slower, costlier request.',
                ],
            ],
        ],

        'ai_task' => [
            'label' => 'Extract with a model',
            'exits' => ['extracted', 'could not'],
            'fields' => [
                'instruction' => [
                    'label' => 'What to pull out',
                    'placeholder' => 'Read the traveller count, the departure city and the month out of the message.',
                    'hint' => 'Describe the values, not the format. The node asks for them by the names below.',
                ],
                'input' => [
                    'label' => 'Read from',
                    'hint' => 'May contain {{variables}}. Left empty, the message the visitor just sent is used.',
                ],
                'fields' => [
                    'label' => 'Values to expect',
                    'hint' => 'One name per value, as it will be read back: adults, city, month.',
                ],
                'into' => [
                    'label' => 'Remember them as',
                    'hint' => 'One variable per value, named trip_adults, trip_city and so on.',
                ],
                'provider' => [
                    'label' => 'Model',
                    'default_option' => 'The one this installation is set to',
                ],
            ],
        ],

        'query' => [
            'label' => 'Look up data',
            'exits' => ['found', 'nothing found'],
            'fields' => [
                'resource' => ['label' => 'Data resource'],
                'mode' => ['label' => 'What to read'],
                'filters' => [
                    'label' => 'Match these fields',
                    'hint' => 'Each pair is a field and the value it must equal. Values may contain {{variables}}.',
                ],
                'select' => [
                    'label' => 'Fields to read',
                    'hint' => 'Leave empty for the fields the resource returns by default.',
                ],
                'limit' => ['label' => 'How many records'],
                'into' => [
                    'label' => 'Remember the result as',
                    'placeholder' => 'booking',
                    'hint' => 'One variable per field of the record, named booking_status, booking_total and so on.',
                ],
            ],
            'issues' => [
                'no_resource' => 'This node does not say which data resource to read.',
                'no_into' => 'This node does not say what to remember the result as, so nothing downstream could use it.',
            ],
        ],

        'choose' => [
            'label' => 'Let the visitor pick a record',
            'exits' => ['picked', 'not in the list'],
            'none_option' => 'Not in the list',
            'fields' => [
                'resource' => ['label' => 'Data resource'],
                'filters' => [
                    'label' => 'Match these fields',
                    'hint' => 'Each pair is a field and the value it must equal. Values may contain {{variables}}.',
                ],
                'limit' => ['label' => 'How many to offer'],
                'label' => [
                    'label' => 'How to label each record',
                    'placeholder' => '{{reference}} to {{destination}}',
                    'hint' => '{{field}} of the record, not of the conversation.',
                ],
                'into' => [
                    'label' => 'Remember the pick as',
                    'placeholder' => 'booking',
                    'hint' => 'One variable per field of the chosen record, named booking_status and so on.',
                ],
                'none_label' => ['label' => 'The last option'],
            ],
            'issues' => [
                'no_resource' => 'This node does not say which records to offer.',
                'no_into' => 'This node does not say what to remember the pick as, so nothing downstream could use it.',
            ],
        ],

        'mutate' => [
            'label' => 'Write a record',
            'exits' => ['written', 'could not'],
            'fields' => [
                'resource' => ['label' => 'Which resource'],
                'operation' => [
                    'label' => 'What to do',
                    'options' => [
                        'insert' => 'Add a record',
                        'update' => 'Change a record',
                    ],
                ],
                'key' => [
                    'label' => 'Which record',
                    'hint' => 'The variable holding the record to change. Usually filled by a lookup above.',
                ],
                'expect' => [
                    'label' => 'As it was when read',
                    'hint' => 'The variable holding the lock column read by the lookup above. Without it the update is refused.',
                ],
                'values' => [
                    'label' => 'Fields to write',
                    'hint' => 'Values support {{variables}}. Only fields the resource declares writable are accepted.',
                ],
                'into' => [
                    'label' => 'Remember the record as',
                    'hint' => 'Receives the key of the row that was written.',
                ],
            ],
            'issues' => [
                'no_resource' => 'This node does not say what it writes to.',
                'no_values' => 'This node writes nothing.',
                'no_key' => 'This node changes a record without saying which one.',
                'no_expect' => 'This node changes a record without saying how it looked when it was read.',
            ],
        ],

        'link' => [
            'label' => 'Link to a record',
            'exits' => ['linked', 'could not'],
            'fields' => [
                'resource' => [
                    'label' => 'Which resource',
                    'hint' => 'The record has to belong to a declared resource. That is where its kind comes from, and its access scopes decide whether this conversation may point at it.',
                ],
                'role' => [
                    'label' => 'Link it as',
                    'options' => [
                        'contact' => 'Who this is',
                        'subject' => 'What this is about',
                    ],
                ],
                'key' => [
                    'label' => 'Which record',
                    'hint' => 'The variable holding the record key — usually filled by a lookup, a pick or a write above.',
                ],
                'replace' => [
                    'label' => 'Replace an existing link',
                    'hint' => 'Off, a conversation already pointing at another record leaves by the second exit. What is there came from the channel or from an earlier turn, and repointing it silently files the thread under the wrong record.',
                ],
            ],
            'issues' => [
                'no_resource' => 'This node does not say which resource the record belongs to.',
                'no_key' => 'This node links a record without saying which one.',
                'bad_role' => 'This node does not say whether it links who the person is or what the conversation is about.',
            ],
        ],

        'approval' => [
            'label' => 'Ask for confirmation',
            'exits' => ['approved', 'declined'],
            'retry' => 'Sorry, I did not catch that. Please answer with one of these:',
            'yes' => 'Yes',
            'no' => 'No',
            'fields' => [
                'yes_label' => ['label' => 'The confirming answer'],
                'no_label' => ['label' => 'The refusing answer'],
                'attempts' => [
                    'label' => 'Attempts',
                    'hint' => 'After the last one an answer that is neither counts as a refusal.',
                ],
            ],
        ],

        'action' => [
            'label' => 'Do something',
            'exits' => ['done', 'could not'],
            'fields' => [
                'capability' => ['label' => 'Action'],
                'input' => [
                    'label' => 'Call it with',
                    'hint' => 'Each pair is an input of the action and the value to pass. Values may contain {{variables}}.',
                ],
                'into' => [
                    'label' => 'Remember the result as',
                    'placeholder' => 'award',
                    'hint' => 'One variable per field the action returns, named award_balance and so on.',
                ],
            ],
            'issues' => [
                'no_action' => 'This node does not say which action to run, so it could only ever take its second exit.',
                'unknown_action' => 'No action is registered under [:key]. The node would fail on every conversation.',
                'unconfirmed_write' => '[:action] changes data. Make sure a confirmation node stands above it on this branch.',
            ],
        ],

        'http' => [
            'label' => 'Ask another system',
            'exits' => ['answered', 'failed'],
            'fields' => [
                'method' => ['label' => 'Method'],
                'url' => [
                    'label' => 'Address',
                    'placeholder' => 'https://api.example.com/orders/{{order_id}}',
                    'hint' => 'May contain {{variables}}. Only domains allowed in the chatbot configuration can be called.',
                ],
                'headers' => ['label' => 'Headers'],
                'body' => [
                    'label' => 'Body',
                    'hint' => 'Sent as JSON when it parses as JSON, as plain text otherwise. May contain {{variables}}.',
                ],
                'path' => [
                    'label' => 'Take from the answer',
                    'placeholder' => 'data.status',
                    'hint' => 'A path inside the JSON answer. Leave empty to keep the whole body.',
                ],
                'into' => [
                    'label' => 'Remember the answer as',
                    'placeholder' => 'order',
                ],
                'timeout' => ['label' => 'Give up after, seconds'],
            ],
            'issues' => [
                'no_url' => 'This node does not say what address to call.',
                'no_into' => 'This node does not say what to remember the answer as, so nothing downstream could use it.',
                'refused_url' => 'This address cannot be called: :reason.',
            ],
        ],

        'transform' => [
            'label' => 'Reshape a value',
            'fields' => [
                'source' => ['label' => 'Take'],
                'operation' => [
                    'label' => 'And',
                    'options' => [
                        'upper' => 'Upper case',
                        'lower' => 'Lower case',
                        'trim' => 'Trim spaces',
                        'first_word' => 'First word',
                        'digits_only' => 'Digits only',
                        'truncate' => 'Shorten to a length',
                        'default' => 'Use a stand-in when empty',
                        'join' => 'Join several variables',
                        'format_date' => 'Format a date',
                        'replace' => 'Replace text',
                    ],
                ],
                'length' => ['label' => 'Keep this many characters'],
                'fallback' => [
                    'label' => 'Stand-in',
                    'hint' => 'Used when the value is empty. May contain {{variables}}.',
                ],
                'with' => [
                    'label' => 'Together with',
                    'hint' => 'More variable names, joined after the first one.',
                ],
                'separator' => ['label' => 'Separated by'],
                'format' => [
                    'label' => 'Date format',
                    'hint' => "PHP's date format — d.m.Y, j F Y, H:i.",
                ],
                'search' => ['label' => 'Find'],
                'replace' => ['label' => 'Replace with'],
                'into' => [
                    'label' => 'Remember the result as',
                    'placeholder' => 'greeting_name',
                ],
            ],
            'issues' => [
                'no_source' => 'This node does not say which value to reshape.',
                'no_into' => 'This node does not say what to remember the result as, so it would do nothing at all.',
                'unknown_operation' => '[:operation] is not an operation this node can perform, so it would write nothing.',
            ],
        ],

        'foreach' => [
            'label' => 'For each entry',
            'exits' => ['each', 'done'],
            'fields' => [
                'source' => [
                    'label' => 'Go through',
                    'hint' => 'A variable holding a list — one entry per line, as a lookup in list mode writes it.',
                ],
                'item' => [
                    'label' => 'Remember each entry as',
                    'placeholder' => 'booking_line',
                ],
                'index' => [
                    'label' => 'Remember its number as',
                    'placeholder' => 'booking_number',
                ],
                'max_items' => [
                    'label' => 'Never more than',
                    'hint' => 'Entries beyond this many are not walked. A loop without a bound is a conversation that never finishes.',
                ],
            ],
            'issues' => [
                'no_source' => 'This node does not say which list to go through.',
                'no_item' => 'This node does not say what to remember each entry as, so the branch below it would read nothing.',
                'no_limit' => 'This loop has no limit. Without one the conversation walks the whole list and may never reach its next answer.',
                'over_ceiling' => 'A loop of more than :ceiling entries holds the conversation open long past the point a visitor waits.',
            ],
        ],

        'handoff' => [
            'label' => 'Hand to a person',
        ],

        'close' => [
            'label' => 'Close',
        ],
    ],

    /**
     * Problems the canvas reports about the drawing as a whole, rather than
     * about one node's settings.
     */
    'graph' => [
        'unknown_kind' => 'This node is of the kind [:kind], which nothing in this installation provides. It is kept as it is, and a conversation reaching it stops.',
        'dangling_exit' => 'Exit :slot of this node leads to [:target], and the scenario has no such node.',
        'no_start' => 'The scenario has no start node, so nothing ever begins it.',
        'many_starts' => 'The scenario has more than one start node, and which of them runs is not something an author can decide from the canvas.',
        'unreachable' => 'Nothing leads to this node from the start, so no conversation reaches it yet.',
        'unconfirmed_write' => 'This node changes data and can be reached without a confirmation node above it. Add one on every path that leads here.',
        'not_publishable' => 'Scenario [:scenario] cannot be published: :count blocking problem found.|Scenario [:scenario] cannot be published: :count blocking problems found.',
    ],

    /**
     * The slots a collect or a form node asks for, and the questions they ask
     * when the node carries no wording of its own.
     */
    'slots' => [
        'name' => [
            'label' => 'Name',
            'question' => 'How should I address you?',
        ],
        'phone' => [
            'label' => 'Phone',
            'question' => 'What is the best number to reach you on?',
        ],
        'email' => [
            'label' => 'Email',
            'question' => 'What is your email address?',
        ],
        'contact_preference' => [
            'label' => 'Preferred way to reach you',
            'question' => 'How would you like us to get back to you?',
            'options' => [
                'phone' => 'A phone call',
                'email' => 'Email',
                'messenger' => 'A messenger',
            ],
        ],
        'company' => [
            'label' => 'Company',
            'question' => 'Which company are you with?',
        ],
        'request' => [
            'label' => 'Request',
            'question' => 'Tell me what you need',
        ],
        'urgency' => [
            'label' => 'Urgency',
            'question' => 'How soon do you need this?',
            'options' => [
                'now' => 'Right now',
                'today' => 'Today',
                'this_week' => 'This week',
                'no_rush' => 'No rush',
            ],
        ],
        'order_number' => [
            'label' => 'Reference number',
            'question' => 'Do you have a reference number for this?',
        ],
    ],

    /** The things a condition node can ask about. */
    'conditions' => [
        'variable' => [
            'label' => 'Collected variable',
            'hint' => 'What the value should be',
        ],
        'channel' => ['label' => 'Channel'],
        'identified' => ['label' => 'Visitor is identified'],
        'has_contact' => ['label' => 'Linked to a record'],
        'returning' => ['label' => 'Has written before'],
        'working_hours' => ['label' => 'Within working hours'],
        'time' => [
            'label' => 'Time of day',
            'hint' => '18:00',
        ],
        'weekday' => [
            'label' => 'Day of the week',
            'options' => [
                'mon' => 'Monday',
                'tue' => 'Tuesday',
                'wed' => 'Wednesday',
                'thu' => 'Thursday',
                'fri' => 'Friday',
                'sat' => 'Saturday',
                'sun' => 'Sunday',
            ],
        ],
        'month' => [
            'label' => 'Month',
            'hint' => '1 to 12',
        ],
        'day' => [
            'label' => 'Day of the month',
            'hint' => '1 to 31',
        ],
        'date' => [
            'label' => 'Date',
            'hint' => '2026-12-31',
        ],
        'turns' => ['label' => 'Messages from the visitor'],
        'only_greeting' => ['label' => 'Said only a greeting'],
        'page' => [
            'label' => 'Page the chat was opened from',
            'hint' => '/pricing',
        ],
        'cookie' => [
            'label' => 'Cookie from the page',
            'hint' => 'utm_campaign',
        ],
        'handed_off' => ['label' => 'Already handed to a person'],
    ],

    /** Variable groups a message template can read from. */
    'variables' => [
        'talk' => [
            'label' => 'Conversation',
            'fields' => [
                'answer' => 'What the visitor just wrote',
                'channel' => 'Channel',
                'visitor' => 'Visitor name',
                'bot' => 'Bot name',
            ],
        ],
    ],

    /**
     * The canvas chrome itself, sent to the browser with the rest of the
     * editor's configuration.
     *
     * The React bundle is committed to `dist/` and cannot be rebuilt by a host,
     * so nothing in it may be translated at build time. It reads these strings
     * out of the payload the page already carries — the one that also describes
     * every node kind — which costs no extra request and keeps the wording in
     * the same files as the rest of the panel.
     */
    /*
     * Scenarios worth starting from, offered on an empty canvas.
     *
     * The wording is what an author reads first, keeps, and edits into their
     * own — so it is written to be kept: no placeholders, no "TODO", nothing
     * that reads as unfinished if it reaches a customer before the author
     * comes back to it.
     */
    'recipes' => [
        'greeting_menu' => [
            'label' => 'Greeting and a menu',
            'description' => 'Says hello, offers three choices, and fetches a person for anything it does not handle.',
            'hello' => 'Hello! I can answer a few things here, or fetch a colleague.',
            'question' => 'What would you like to do?',
            'option_question' => 'Ask a question',
            'option_order' => 'About my order',
            'option_human' => 'Talk to a person',
            'anything_else' => 'Anything else I can help with?',
            'human' => 'One moment — I will fetch a colleague.',
        ],
        'knowledge_desk' => [
            'label' => 'Answer from the knowledge base',
            'description' => 'Sends whatever is typed to the knowledge base, and hands the thread over when it does not know.',
            'more' => 'Anything else?',
            'sorry' => 'I do not have that one. Let me fetch a colleague.',
        ],
        'callback_form' => [
            'label' => 'Take a name and a phone number',
            'description' => 'Asks for the details the desk needs to call somebody back, then hands the thread over.',
            'why' => 'Leave your details and we will call you back.',
            'thanks' => 'Thank you — a colleague will be in touch.',
        ],
    ],

    'canvas' => [
        'recipes' => 'Start from',
        'recipe_inserted' => 'Added to the canvas. Nothing is saved until you press Save.',
        'add_step' => 'Add step',
        'add_note' => 'Add note',
        'auto_arrange' => 'Auto-arrange',
        'counts' => ':blocking blocking · :notices notices',
        'check' => 'Check',
        'save' => 'Save',
        'delete' => 'Delete',
        'restart' => 'Restart',
        'send' => 'Send',
        'sandbox_dirty' => 'The bot answers from the saved scenario. Save to try what is on the canvas now.',
        'sandbox_intro' => 'Whatever you say enters this scenario at its start node, whatever the routing would have done with it.',
        'another_name' => 'Another name…',
        'no_branches' => 'This node has no branches yet, so it has nowhere to go.',
        'values_placeholder' => 'value, value, value',
        'foreign_kind' => 'Nothing in this installation provides [:kind]. Its settings are kept untouched and cannot be edited here.',
        'picker_empty' => 'Nothing installed matches that.',
        'picker_more' => 'More (:count)',
        'unsaved' => 'unsaved',
        'required' => 'required',
        'move_up' => 'Move up',
        'move_down' => 'Move down',
        'remove' => 'Remove',
        'key_placeholder' => 'key',
        'value_placeholder' => 'value',
        'variable_placeholder' => 'variable name',
        'words' => 'Words',
        'word_placeholder' => 'word or phrase',
        'when' => 'When',
        'branches' => 'Branches',
        'branch_placeholder' => 'Branch label',
        'branch_default' => 'Branch :number',
        'add_branch' => 'Add a branch',
        'otherwise' => 'otherwise',
        'next' => 'next',
        'node_key_hint' => 'The key this node is known by, in transitions and in the run journal',
        'message' => 'Message',
        'message_placeholder' => 'What the bot says here',
        'find_kind' => 'Find a node kind',
        'usually_next' => 'Usually next',
        'basics' => 'Basics',
        'everything_else' => 'Everything else',
        'unknown_kind' => 'Unknown kind',
        'sandbox_step' => 'Step :number of the sandbox conversation',
        'note_placeholder' => 'Double click to write a note',
        'try_it' => 'Try it',
        'conversation_state' => 'The conversation is :state.',
        'say_to_start' => 'Say something to start.',
        'say_placeholder' => 'Say something to the bot',
        'walked' => 'Nodes it walked',
        'capability_no_input' => 'This action takes no settings.',
        'scenario_variables' => 'Collected by this scenario',
        'variables_heading' => 'Variables you can use',
        'variables_hint' => 'Click one to paste it where you were typing. In a message they are written {{ name }} — a name that matches nothing prints as an empty gap.',
        'variables_empty' => 'Nothing in this scenario names a value yet. A form, a collect or a set node does.',
    ],
];
