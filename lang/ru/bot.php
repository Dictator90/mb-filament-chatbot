<?php

declare(strict_types=1);

/**
 * The Russian wording of what the bot says on its own. See `lang/en/bot.php`
 * for what belongs here and what deliberately does not.
 */
return [

    /**
     * Russian greets in more forms than English does, and the list stays a list
     * of whole openings rather than of stems: a stem like «привет» would also
     * match «приветствую вас, у меня сломался заказ», and that message is a
     * question, not an opening.
     */
    'greetings' => [
        'привет', 'приветствую', 'здравствуйте', 'здравствуй', 'здорово', 'салют',
        'доброе утро', 'добрый день', 'добрый вечер', 'доброго дня', 'доброго времени суток',
        'добрый', 'утро доброе',
    ],

    /**
     * Согласие и отказ по-русски пишут короче, чем по-английски, и «ок»
     * встречается чаще, чем «да». Список нужен ровно затем, чтобы «ок» не
     * прочиталось как отказ прямо перед записью данных.
     */
    'approval' => [
        'yes' => ['да', 'ага', 'угу', 'ок', 'окей', 'хорошо', 'давайте', 'давай', 'подтверждаю', 'согласен', 'согласна', 'верно'],
        'no' => ['нет', 'не', 'неа', 'отмена', 'отменить', 'стоп', 'отказываюсь', 'не надо'],
    ],
];
