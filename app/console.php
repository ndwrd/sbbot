<?php

// Точка входа для одноразовых команд на ноде — main дёргает её по SSH
// (docker exec в php-контейнер ноды) вместо переписывания логики трейтов
// в shell. $bot->input — заглушка: на ноде нет своего Telegram-чата, все
// send()/update() внутри вызываемых методов просто безрезультатно уйдут в
// Telegram API — сообщение админу шлёт вызывающая сторона (main), не нода.
require __DIR__ . '/bot.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';

$bot = new Bot($c['key'], $i);
$bot->input = [
    'chat'        => null,
    'message_id'  => null,
    'username'    => 'console',
    'callback_id' => false,
    'file_id'     => false,
];

$method = $argv[1] ?? null;
$args   = array_slice($argv, 2);

if (empty($method) || !method_exists($bot, $method)) {
    fwrite(STDERR, "unknown method: $method\n");
    exit(1);
}

$result = $bot->$method(...$args);
if (is_string($result)) {
    echo $result, "\n";
} elseif ($result !== null) {
    echo json_encode($result), "\n";
}
