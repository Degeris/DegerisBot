<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user varchar(200) NOT NULL,
        bot_token varchar(200) NOT NULL,
        admin_ids TEXT NOT NULL,
        username varchar(200) NOT NULL,
        setting TEXT NULL,
        hide_panel JSON NOT NULL,
        time varchar(200) NOT NULL
        SQL,
    'columns' => [
        ['hide_panel', '{}', 'JSON'],
    ],
];
