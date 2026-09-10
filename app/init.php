<?php

ini_set('session.use_cookies', 0);

require __DIR__ . '/timezone.php';

require __DIR__ . '/bot.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';
if ($c['debug']) {
    require __DIR__ . '/debug.php';
}

if (!empty($c['node'])) {
    // Нода не имеет настоящего токена бота — polling() тут всегда получал
    // бы 401 от Telegram и никогда не создавал /start (тот пишется только
    // после первого успешного getUpdates), из-за чего php-healthcheck
    // (check_file.sh) не проходил бы вообще никогда. Контейнер существует
    // не ради поллинга, а как цель для `docker exec ... console.php` с
    // главного — просто помечаем /start и держим процесс живым.
    file_put_contents('/start', 1);
    while (true) {
        sleep(3600);
    }
}

$bot = new Bot($c['key'], $i);
$bot->cleanQueue();
$bot->setcommands();
$bot->polling();
