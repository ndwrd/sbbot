<?php

require __DIR__ . '/timezone.php';
require __DIR__ . '/config.php';
if ($c['debug']) {
    require __DIR__ . '/debug.php';
}
require __DIR__ . '/calc.php';
require __DIR__ . '/bot.php';
require __DIR__ . '/i18n.php';
if (file_exists(__DIR__ . '/override.php')) {
    include __DIR__ . '/override.php';
}
$bot  = new Bot($c['key'], $i);
$hash = $bot->getHashBot();
$webapp = false;
if (!empty($_GET['hash'])) {
    $t = $_GET;
    unset($t['hash']);
    ksort($t);
    // $s = [] обязателен: если в запросе нет ничего, кроме hash, массив так и
    // не создастся, и implode() ниже получит null — на PHP 8 это уже не
    // warning, а TypeError, то есть 500 вместо тихого отказа авторизации.
    $s = [];
    foreach ($t as $k => $v) {
        $s[] = "$k=$v";
    }
    $s      = implode("\n", $s);
    $sk     = hash_hmac('sha256', $c['key'], "WebAppData", true);
    // hash_equals(), а не ==: обычное сравнение строк выходит на первом
    // несовпавшем байте, и по времени ответа подпись можно подбирать побайтно.
    $webapp = hash_equals(hash_hmac('sha256', $s, $sk), (string) $_GET['hash']);
}

switch (true) {
    // hash_equals() по той же причине, что и выше; ?? '' — без параметра k
    // иначе undefined key.
    case 'POST' == $_SERVER['REQUEST_METHOD'] && preg_match('~^/tlgrm~', $_SERVER['REQUEST_URI']) && hash_equals($c['key'], (string) ($_GET['k'] ?? '')):
        $bot->input();
        break;

    case preg_match('~^' . preg_quote("/webapp$hash/save") . '~', $_SERVER['REQUEST_URI']) && $webapp && !empty($_POST['json']):
        echo json_encode($bot->saveTemplate($_POST['name'], $_POST['type'], $_POST['json']));
        break;

    case preg_match('~^' . preg_quote("/pac$hash/sub") . '~', $_SERVER['REQUEST_URI']) && file_exists(__DIR__ . '/subscription.php'):
        $bot->sub();
        exit;

    case preg_match('~^' . preg_quote("/pac$hash") . '~', $_SERVER['REQUEST_URI']):
        // [2] ?? '' — на /pac{hash} без третьего сегмента (например
        // /pac{hash}?t=te&ty=sing из меню шаблонов) индекса просто нет.
        //
        // allowed_classes: false — сюда всегда приходит обычный массив
        // ['h'=>..,'t'=>..,'s'=>..], который сам бот и сериализовал (см. $link()
        // в sub()). Голый unserialize() на строке из URL позволял бы собрать
        // объект произвольного класса и дотянуться до его __wakeup/__destruct.
        // Путь закрыт секретным хэшем, но одна строка дешевле, чем надеяться,
        // что хэш никогда не утечёт.
        //
        // is_array() — с allowed_classes:false объект превращается в
        // __PHP_Incomplete_Class, который !empty() пропускает, а array_merge()
        // уже роняет TypeError'ом.
        //
        // @ — на любой мусор в URL (а его шлют постоянно) unserialize() пишет
        // E_WARNING "Error at offset 0"; невалидный ввод тут штатный случай, а
        // не ошибка, и false он и так вернёт.
        $seg = explode('/', $_SERVER['REQUEST_URI'])[2] ?? '';
        $t   = $seg === '' ? null : @unserialize(base64_decode($seg), ['allowed_classes' => false]);
        if (!empty($t) && is_array($t)) {
            $_GET = array_merge($_GET, $t);
        }
        $type = $_GET['t'] ?? 'pac';
        switch ($type) {
            case 's':
            case 'si':
            case 'cl':
            case 'hp':
                $bot->subscription();
                exit;

            case 'te':
                // Белый список: $_GET['ty'] подставляется прямо в путь
                // /config/{ty}.json, то есть без проверки это чтение
                // произвольного .json с диска контейнера. Значения ровно те три,
                // что шлёт сам бот (см. "/templates xray|sing|clash" в
                // singboxTemplates()); всё остальное — не наш запрос.
                $ty = in_array($_GET['ty'] ?? '', ['xray', 'sing', 'clash'], true) ? $_GET['ty'] : null;
                if ($ty === null) {
                    break;
                }
                if (!empty($_GET['te'])) {
                    $t = $bot->getPacConf()["{$ty}templates"][$_GET['te']] ?? null;
                } else {
                    $t = json_decode(file_get_contents("/config/$ty.json"), true);
                }
                if ($t) {
                    header('Content-Type: text/html');
                    $t = json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $name = ($_GET['te'] ?? null) ?: 'origin';
                    $type = $ty;
                    echo <<<HTML
                        <!DOCTYPE HTML>
                        <html lang="en" style="height:100%">
                        <head>
                            <!-- when using the mode "code", it's important to specify charset utf-8 -->
                            <meta charset="utf-8">
                            <meta name="viewport" content="width=device-width, initial-scale=1.0">

                            <link href="/webapp$hash/jsoneditor.min.css" rel="stylesheet" type="text/css">
                            <script src="/webapp$hash/jsoneditor.min.js"></script>
                            <script src="/webapp$hash/jquery-3.7.1.min.js"></script>
                            <script src="https://telegram.org/js/telegram-web-app.js"></script>
                        </head>
                        <body style="height:100%">
                            <div id="jsoneditor" style="height:100%"></div>

                            <script>
                                jQuery(function($) {
                                    var tg = window.Telegram.WebApp;
                                    const container = document.getElementById("jsoneditor")
                                    const options = {}
                                    const editor = new JSONEditor(container, options)
                                    editor.set({$t})
                                    tg.MainButton.show().setText('{$bot->i18n('save')}').onClick(function (e) {
                                        var self = this;
                                        $.ajax({
                                            url: '/webapp$hash/save?' + tg.initData,
                                            method: 'POST',
                                            data: {
                                                name: '$name',
                                                type: '$type',
                                                json: editor.getText()
                                            },
                                            dataType: 'json'
                                        }).done(function (r) {
                                            if (r.status == true) {
                                                tg.MainButton.setText('{$bot->i18n('success')}')
                                                setTimeout(() => {
                                                    tg.close();
                                                }, 500);
                                            } else {
                                                tg.MainButton.setText(r.message);
                                            }
                                        }).fail(function (r) {
                                            tg.MainButton.setText('{$bot->i18n('error')}')
                                        });
                                    });
                                });
                            </script>
                        </body>
                        </html>
                        HTML;
                    exit;
                }
        }
        break;

    default:
        // http_response_code(), а не header('500', true, 500): второй аргумент
        // статус действительно ставил, но сама строка '500' при этом уходила
        // ещё и как сырой заголовок — без двоеточия, из-за чего Unit писал в
        // лог "[unit] colon not found in header '500'" на каждый такой ответ.
        http_response_code(500);
}
