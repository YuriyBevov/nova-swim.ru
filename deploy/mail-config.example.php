<?php
// Скопировать на сервер в <DEPLOY_PATH>/shared/mail-config.php.
// Настоящий пароль хранится только на сервере, вне public_html и Git.
return [
    'smtp' => [
        'host' => 'smtp.example.com',
        'username' => 'info@nova-swim.ru',
        'password' => '', // Вставить пароль приложения или SMTP-пароль.
        'secure' => 'ssl', // ssl для 465; tls для 587 — уточнить у провайдера.
        'port' => 465,
    ],
    'from' => [
        'email' => 'info@nova-swim.ru',
        'name' => 'Nova Swim',
    ],
    'recipients' => [
        'info@nova-swim.ru' => 'Администратор Nova Swim',
        // 'manager@example.com' => 'Менеджер',
    ],
    'allowed_origins' => [
        'https://nova-swim.ru',
        'https://www.nova-swim.ru',
    ],
];
