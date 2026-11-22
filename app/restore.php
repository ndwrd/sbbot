<?php

// Восстановление настроек из резервной копии (backup.php) из командной строки:
// пригодится, когда бот ещё не отвечает в Telegram — например, сразу после
// переустановки, пока в нём нечем нажать «import».
//
// usage: php restore.php [файл] [--check]
//   файл    - путь к json-бэкапу; не указан или '-' — читается stdin
//   --check - только проверить файл и показать, какие в нём разделы
//
// примеры:
//   make restore FILE=backup.json
//   make restore-check FILE=backup.json
//   docker compose exec -T php php restore.php < backup.json

$args  = array_slice($argv, 1);
$check = false;
$file  = '';
foreach ($args as $arg) {
    if ($arg == '--check' || $arg == '-n') {
        $check = true;
    } elseif ($arg == '--help' || $arg == '-h') {
        echo "usage: php restore.php [file] [--check]\n";
        exit(0);
    } elseif ($file === '') {
        $file = $arg;
    }
}

if ($file === '' || $file == '-') {
    $raw = stream_get_contents(STDIN);
} else {
    $raw = @file_get_contents($file);
}

if (empty($raw)) {
    fwrite(STDERR, 'restore: empty input' . ($file ? " ($file)" : '') . "\n");
    exit(1);
}

$json = json_decode($raw, true);
if (empty($json) || !is_array($json)) {
    fwrite(STDERR, 'restore: invalid json' . (json_last_error() ? ': ' . json_last_error_msg() : '') . "\n");
    exit(1);
}

// Разделы, которые разбирает Bot::importFile(). 'xray' — признак бэкапа
// vpnbot: importFile() сам переведёт его в формат sbbot.
$sections = ['ssl', 'pac', 'origin', 'mtproto', 'mtprotodomain', 'mtprotoadtag', 'singbox', 'dnstt', 'xray'];
$found    = array_values(array_intersect($sections, array_keys($json)));
if (empty($found)) {
    fwrite(STDERR, "restore: no known sections in backup\n");
    exit(1);
}

if ($check) {
    $kind = !empty($json['xray']) && empty($json['singbox']) ? 'vpnbot (будет переведён в формат sbbot)' : 'sbbot';
    echo "backup: $kind\n";
    echo 'sections: ' . implode(', ', $found) . "\n";
    exit(0);
}

require __DIR__ . '/timezone.php';

require __DIR__ . '/bot.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';
// debug.php не подключаем: он читает php://input и ломает восстановление из stdin.

// В консоли нет чата Telegram: прогресс печатаем в stdout вместо запросов в
// api.telegram.org.
class RestoreBot extends Bot
{
    private $shown = '';

    // importFile() каждый раз перерисовывает одно сообщение целиком, дописывая в
    // него новую строку. В чате это выглядит как один растущий список, а в
    // консоли дало бы лесенку из повторов — печатаем только добавленное.
    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML')
    {
        $text = (string) $text;
        if ($this->shown !== '' && str_starts_with($text, $this->shown)) {
            $new = ltrim(substr($text, strlen($this->shown)), "\n");
        } else {
            $new = $text;
        }
        if ($new !== '') {
            echo $new . "\n";
        }
        $this->shown = $text;
        return null;
    }

    public function send($chat, $text, ?int $to = 0, $button = false, $reply = false, $mode = 'HTML', $disable_notification = false)
    {
        if (!is_null($text)) {
            echo $text . "\n";
        }
        return null;
    }

    public function answer($callback_id, $textNotify = false, $notify = false)
    {
        if ($textNotify) {
            echo $textNotify . "\n";
        }
        return true;
    }
}

$bot = new RestoreBot($c['key'], $i);
// Часть методов читает input напрямую — без него были бы warning'и.
$bot->input = [
    'chat'        => 0,
    'message_id'  => 0,
    'callback_id' => 0,
    'username'    => '',
    'from'        => 0,
];

echo 'restore: ' . implode(', ', $found) . "\n";

$tmp = tempnam(sys_get_temp_dir(), 'sbbot-restore');
if ($tmp === false) {
    fwrite(STDERR, "restore: can not create temp file\n");
    exit(1);
}
file_put_contents($tmp, $raw);

try {
    $bot->importFile($tmp);
} catch (Throwable $e) {
    @unlink($tmp);
    fwrite(STDERR, 'restore failed: ' . $e->getMessage() . "\n");
    exit(1);
}
@unlink($tmp);

echo "restore complete\n";
