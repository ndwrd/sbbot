<?php

trait BotPacTrait
{
public function getPacConf()
    {
        // Пустой/битый/ещё не созданный pac.json даёт json_decode() null — а
        // setPacConf() принимает только array (строгая типизация), так что любой
        // caller вида "$c = getPacConf(); ...; setPacConf($c)" падает TypeError'ом
        // на всём service.php (см. selfUpdate()). Гарантируем массив на выходе,
        // а не только у отдельных вызовов.
        return json_decode(file_get_contents($this->pac), true) ?: [];
    }

public function setPacConf(array $conf)
    {
        return file_put_contents($this->pac, json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

public function include($type)
    {
        switch ($type) {
            case 'rulessetlist':
                $r = $this->send(
                    $this->input['chat'],
                    "@{$this->input['username']} outbound[:behavior]:time:URL",
                    $this->input['message_id'],
                    reply: 'outbound[:behavior]:time:URL',
                );
                break;

            default:
                $r = $this->send(
                    $this->input['chat'],
                    "@{$this->input['username']} list separated by commas",
                    $this->input['message_id'],
                    reply: 'list separated by commas',
                );
                break;
        }
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'addInclude',
            'args'          => [$type],
        ];
    }

public function addInclude(string $domains, $type)
    {
        if ($type == 'rulessetlist' && !preg_match('~^.+:.+:https?://.+~', $domains)) {
            $this->send($this->input['from'], 'wrong pattern, enter [direct|block|proxy|custom outbound]:time:URL');
            return;
        }
        $domains = explode(',', $domains);
        $domains = array_filter($domains, fn($x) => !empty(trim($x)));
        if (!empty($domains)) {
            $conf = $this->getPacConf();
            foreach ($domains as $k => $v) {
                if (in_array($type, ['white', 'deny'])) {
                    $conf[$type][] = $v;
                } else {
                    $conf[$type][in_array($type, ['rulessetlist', 'packagelist', 'processlist']) ? trim($v) : idn_to_ascii(trim($v))] = true;
                }
            }
            ksort($conf[$type]);
            $this->setPacConf($conf);
            $page = (int) floor(array_search($v, array_keys($conf[$type])) / $this->limit);
        }
        $page = $page ?: -2;
        $this->backXtlsList($type, $page);
    }

public function deleteYes($type)
    {
        $c = $this->getPacConf();
        unset($c[$type]);
        $this->setPacConf($c);
        switch ($type) {
            case 'includelist':
                $this->xtlsproxy();
                break;
            case 'blocklist':
                $this->xtlsblock();
                break;
            case 'warplist':
                $this->xtlswarp();
                break;
            case 'packagelist':
                $this->xtlsapp();
                break;
            case 'processlist':
                $this->xtlsprocess();
                break;
            case 'rulessetlist':
                $this->xtlsrulesset();
                break;
        }
    }

public function deleteAll($type)
    {
        switch ($type) {
            case 'includelist':
                $dir = 'domains';
                break;
            case 'warplist':
                $dir = 'WARP';
                break;
            case 'blocklist':
                $dir = 'BLOCK';
                break;
            case 'packagelist':
                $dir = 'PACKAGE';
                break;
            case 'processlist':
                $dir = 'PROCESS';
                break;
            case 'subnetlist':
                $dir = 'SUBNET';
                break;
            case 'rulessetlist':
                $dir = 'rulesset';
                break;
        }
        $text   = <<<text
                Menu -> $dir -> delete all
                text;
        $data[] = [
            [
                'text'          => $this->i18n('yes'),
                'callback_data' => "/deleteYes $type",
            ],
        ];
        switch ($type) {
            case 'includelist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsproxy",
                    ],
                ];
                break;
            case 'warplist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlswarp",
                    ],
                ];
                break;
            case 'blocklist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsblock",
                    ],
                ];
                break;
            case 'packagelist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsapp",
                    ],
                ];
                break;
            case 'processlist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsprocess",
                    ],
                ];
                break;
            case 'subnetlist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlssubnet",
                    ],
                ];
                break;
            case 'rulessetlist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsrulesset",
                    ],
                ];
                break;
        }
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            $text,
            $data ?: false,
        );
    }

public function exportList($type)
    {
        $domains = $this->getPacConf()[$type];
        if (!empty($domains)) {
            foreach ($domains as $k => $v) {
                $text .= "$k;$v\n";
            }
            $this->sendFile(
                $this->input['chat'],
                new CURLStringFile($text, "$type.csv", 'application/csv'),
                to: $this->input['message_id'],
            );
        }
    }

public function listPac($type, $page, $menu, $basename = false)
    {
        $data[] = [
            [
                'text'          => $this->i18n('add'),
                'callback_data' => "/include $type",
            ],
        ];
        $domains = $this->getPacConf()[$type];
        if (!empty($domains)) {
            $all     = (int) ceil(count($domains) / $this->limit);
            $page    = min($page, $all - 1);
            $page    = $page < 0 ? $all - 1 : $page;
            $domains = array_slice($domains, $page * $this->limit, $this->limit, true);
            $i = 0;
            foreach ($domains as $k => $v) {
                if ($type == 'rulessetlist') {
                    $text[] = "<blockquote><code>$k</code></blockquote>";
                }
                $data[] = [
                    [
                        'text'          => $this->i18n($v ? 'on' : 'off') . ' ' . ($basename ? basename($k) . ' ' : '') . (in_array($type, ['rulessetlist', 'packagelist', 'processlist', 'subnetlist']) ? $k : idn_to_utf8($k)),
                        'callback_data' => "/change$type " . ($i + $page * $this->limit) . " $page",
                    ],
                    [
                        'text'          => 'delete',
                        'callback_data' => "/delete$type " . ($i + $page * $this->limit) . " $page",
                    ],
                ];
                $i++;
            }
            if ($all > 1) {
                $data[] = [
                    [
                        'text'          => '<<',
                        'callback_data' => "/$menu " . ($page - 1 >= 0 ? $page - 1 : $all - 1),
                    ],
                    [
                        'text'          => $page + 1,
                        'callback_data' => "/$menu $page",
                    ],
                    [
                        'text'          => '>>',
                        'callback_data' => "/$menu " . ($page < $all - 1 ? $page + 1 : 0),
                    ],
                ];
            }
            $data[] = [
                [
                    'text'          => $this->i18n('delete all'),
                    'callback_data' => "/deleteAll $type",
                ],
                [
                    'text'          => $this->i18n('export'),
                    'callback_data' => "/exportList $type",
                ],
                [
                    'text'          => $this->i18n('import'),
                    'callback_data' => "/importList $type",
                ],
            ];
        } else {
            $data[] = [
                [
                    'text'          => $this->i18n('import'),
                    'callback_data' => "/importList $type",
                ],
            ];
        }
        return [$data, $text];
    }

public function listPacChange($type, $action, $key, $page = 0)
    {
        $conf = $this->getPacConf();
        $i = 0;
        foreach ($conf[$type] as $k => $v) {
            if ($key == $i) {
                switch ($action) {
                    case 'change':
                        $conf[$type][$k] = !$v;
                        break;
                    case 'delete':
                        unset($conf[$type][$k]);
                        break;
                }
                break;
            }
            $i++;
        }
        $this->setPacConf($conf);
        $this->backXtlsList($type, $page);
    }
}
