<?php

trait BotIpAnalysisTrait
{
public function autoAnalyzeLogs()
    {
        try {
            $pac = $this->getPacConf();
            if (!empty($pac['autoscan'])) {
                require dirname(__DIR__) . '/config.php';
                if (!empty($c['admin']) && (empty($this->time3) || ((time() - $this->time3) > $pac['autoscan_timeout']))) {
                    $this->time3 = time();
                    $r = $this->analysisIp(return: 1);
                    if (!empty($r)) {
                        foreach ($r as $k => $v) {
                            foreach ($v as $i) {
                                $t[$i['title']][$k] = 1;
                            }
                        }
                        foreach ($t as $k => $v) {
                            $text .= "\n" . count($v) . " $k";
                        }
                        if (!empty($pac['autodeny'])) {
                            $this->denyIp(array_keys($r));
                            $ban = count(array_keys($r));
                            foreach (array_keys($r) as $v) {
                                $ips[] = [[
                                    'text'          => $v,
                                    'callback_data' => "/searchLogs $v",
                                ]];
                            }
                        }
                        if ($pac['silence'] == 0 || $pac['silence'] == 1) {
                            foreach ($c['admin'] as $k => $v) {
                                $this->send($v, "suspicious ips found: $text" . ($ban ? "\nbanned:$ban" : ''), button: $ips ?: [[
                                    [
                                        'text'          => $this->i18n('analyze'),
                                        'callback_data' => '/analysisIp',
                                    ],
                                ]], disable_notification: $pac['silence'] ? true : false);
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            file_put_contents('/logs/php_error', $e->getMessage());
        }
    }

public function checkLogs()
    {
        $c = $this->getPacConf();
        if (!empty($c['autocleanlogs'])) {
            $now = time();
            [$start, $period] = explode('/', $c['autocleanlogs']);
            $start  = strtotime(trim($start));
            $period = strtotime(trim($period), 0);

            if (
                !empty($start)
                && !empty($period)
                && $now >= $start
            ) {
                // Вычисляем, сколько полных периодов прошло с момента start
                $elapsed = $now - $start;
                $periodsElapsed = floor($elapsed / $period);

                // Время последней плановой очистки логов
                $lastScheduledClean = $start + ($periodsElapsed * $period);

                // Проверяем, делали ли уже очистку в этом периоде
                $lastCleanTime = $c['last_clean_logs_time'] ?? 0;

                // Если последняя очистка была сделана до начала текущего периода - делаем очистку
                if ($lastCleanTime < $lastScheduledClean) {
                    $c['last_clean_logs_time'] = $now;
                    $this->setPacConf($c);
                    $this->cleanLog();
                }
            }
        }
    }

public function switchScanIp()
    {
        $c = $this->getPacConf();
        $c['autoscan'] = $c['autoscan'] ? 0 : 1;
        $this->setPacConf($c);
        $this->ipMenu();
    }

public function switchBanIp()
    {
        $c = $this->getPacConf();
        $c['autodeny'] = $c['autodeny'] ? 0 : 1;
        $this->setPacConf($c);
        $this->ipMenu();
    }

public function switchSilence()
    {
        $c = $this->getPacConf();
        $c['silence'] = ((($c['silence'] ?? null) ?: 0) + 1) % 3;
        $this->setPacConf($c);
        $this->ipMenu();
    }

public function ipMenu()
    {
        $text   = 'Settings -> IP & Logs';
        $pac    = $this->getPacConf();
        $d      = count(($pac['deny'] ?? null) ?: []);
        $w      = count(($pac['white'] ?? null) ?: []);
        $data[] = [
            [
                'text'          => $this->i18n('autoscan') . ': ' . ($pac['autoscan'] ? $this->getTime(strtotime((($pac['autoscan_timeout'] ?? null) ?: 3600) . ' seconds')) : $this->i18n('off')),
                'callback_data' => '/autoScanTimeout',
            ],
        ];
        if (!empty($pac['autoscan'])) {
            $data[] = [
                [
                    'text'          => $this->i18n('autoblock') . ': ' . $this->i18n($pac['autodeny'] ? 'on' : 'off'),
                    'callback_data' => '/switchBanIp',
                ],
                [
                    'text'          => $this->i18n('notify') . ': ' . ((function ($pac) {
                        switch ($pac['silence'] ?? 0) {
                            case 0:
                                return '🔊';
                            case 1:
                                return '🔈';
                            case 2:
                                return '🔇';
                        }
                    })($pac)),
                    'callback_data' => '/switchSilence',
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('ignorelist') . ": $w",
                'callback_data' => '/denyList 0 1',
            ],
            [
                'text'          => $this->i18n('blocklist') . ": $d",
                'callback_data' => '/denyList 0 0',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('analyze'),
                'callback_data' => '/analysisIp',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu config",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            $text,
            $data ?: false,
        );
    }

public function ipInRange($ip, $range) {
        [$range, $netmask] = explode('/', $range, 2);
        $rangeDecimal      = ip2long($range);
        $ipDecimal         = ip2long($ip);
        $wildcardDecimal   = pow(2, 32 - $netmask) - 1;
        $netmaskDecimal    = ~$wildcardDecimal;
        return ($ipDecimal & $netmaskDecimal) == ($rangeDecimal & $netmaskDecimal);
    }

public function suspicious($regexp, $file, $ranges, $title, $reverse = false)
    {
        if ($r = fopen($file, 'r')) {
            while (feof($r) === false) {
                $l = fgets($r);
                if (preg_match('~(\d+\.\d+\.\d+\.\d+)~', $l, $m)) {
                    if ($reverse xor preg_match($regexp, $l)) {
                        if (is_array($ranges)) {
                            $flag = true;
                            foreach ($ranges as $range) {
                                if ($this->ipInRange($m[1], $range)) {
                                    $flag = false;
                                    break;
                                }
                            }
                            if ($flag) {
                                $ret[$m[1]][] = [
                                    'title' => $title,
                                    'log'   => $l,
                                ];
                            }
                        } else {
                            if ($this->ipInRange($m[1], $ranges)) {
                                $ret[$m[1]][] = [
                                    'title' => $title,
                                    'log'   => $l,
                                ];
                            }
                        }
                    }
                }
            }
            fclose($r);
        }
        return $ret ?: [];
    }

public function analysisIp(int $page = 0, $return = false)
    {
        $pac = $this->getPacConf();
        $xr  = [];
        foreach (array_merge(($pac['white'] ?? null) ?: [], ($pac['deny'] ?? null) ?: [], ['10.10.0.0/23']) as $v) {
            if (preg_match('~^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})(?:(/\d{1,2}))?$~', $v, $m)) {
                if (!in_array($m[1] . (($m[2] ?? null) ?: '/32'), $xr)) {
                    $xr[] = $m[1] . (($m[2] ?? null) ?: '/32');
                }
            }
        }
        if ($r = fopen('/logs/nginx_tlgrm_access', 'r')) {
            while (feof($r) === false) {
                $l = fgets($r);
                if (preg_match('~(\d+\.\d+\.\d+\.\d+)~', $l, $m)) {
                    if (!in_array("{$m[1]}/32", $xr)) {
                        $xr[] = "{$m[1]}/32";
                    }
                }
            }
            fclose($r);
        }
        if ($r = fopen('/logs/nginx_doh_access', 'r')) {
            while (feof($r) === false) {
                $l = fgets($r);
                if (preg_match('~(\d+\.\d+\.\d+\.\d+)~', $l, $m)) {
                    if (!in_array("{$m[1]}/32", $xr)) {
                        $xr[] = "{$m[1]}/32";
                    }
                }
            }
            fclose($r);
        }
        // sing-box: формат строки лога иной (нет "accepted") — regex подобран по документации,
        // нужно перепроверить по реальному выводу /logs/singbox.log при первом тесте.
        if ($r = fopen('/logs/singbox.log', 'r')) {
            while (feof($r) === false) {
                $l = fgets($r);
                if (preg_match('~from (\d+\.\d+\.\d+\.\d+)~', $l, $m)) {
                    if (!in_array("{$m[1]}/32", $xr)) {
                        $xr[] = "{$m[1]}/32";
                    }
                }
            }
            fclose($r);
        }

        $t = [
            $this->suspicious($this->reg, '/logs/nginx_default_access', $xr, 'possibly a scanner', true),
            $this->suspicious($this->reg, '/logs/nginx_domain_access', $xr, 'possibly a scanner', true),
        ];

        $ip = [];
        foreach ($t as $r) {
            foreach ($r as $k => $v) {
                $ip[$k] = $v;
            }
        }

        $r = $this->suspicious('~\d+\.\d+\.\d+\.\d+.+200\s\d+\s0$~', '/logs/upstream_access', $xr, 'possibly a Reality Degenerate');
        if (!empty($r)) {
            foreach ($r as $k => $v) {
                if (count($v) > 30) {
                    $ip[$k] = $v;
                }
            }
        }

        if (!empty($return)) {
            return $ip;
        }
        if (!empty($ip)) {
            foreach ($ip as $k => $v) {
                $data[] = [
                    [
                        'text'          => $k,
                        'callback_data' => "/searchLogs $k analysisIp $page 0",
                    ]
                ];
            }
            $all  = (int) ceil(count($data) / $this->limit);
            $page = min($page, $all - 1);
            $page = $page < 0 ? $all - 1 : $page;
            $data = array_slice($data ?: [], $page * $this->limit, $this->limit);
            if ($all > 1) {
                $data[] = [
                    [
                        'text'          => '<<',
                        'callback_data' => "/analysisIp " . ($page - 1 >= 0 ? $page - 1 : $all - 1),
                    ],
                    [
                        'text'          => $page + 1,
                        'callback_data' => "/analysisIp $page",
                    ],
                    [
                        'text'          => '>>',
                        'callback_data' => "/analysisIp " . ($page < $all - 1 ? $page + 1 : 0),
                    ],
                ];
            }
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/ipMenu",
            ],
        ];
        $this->update($this->input['from'], $this->input['message_id'], count($ip) ?: 'empty', $data);
    }

public function searchLogs($search, $fun = false, $page = 0, $white = 0)
    {
        if (preg_match('~^\d+\.\d+\.\d+\.\d+$~', $search)) {
            $info = file_get_contents("https://ipinfo.io/$search/json", context: stream_context_create(['http' => ['timeout' => 2]]));
            $text = "$search\n<pre>$info</pre>";
            $data[] = [
                [
                    'text'          => $this->i18n('block'),
                    'callback_data' => "/denyIp $search" . ($fun ? " $fun $page $white" : ''),
                ],
                [
                    'text'          => $this->i18n('ignore'),
                    'callback_data' => "/whiteIp $search" . ($fun ? " $fun $page $white" : ''),
                ],
            ];
            $data[] = [
                [
                    'text'          => $this->i18n('all logs'),
                    'callback_data' => "/searchIp $search",
                ],
                [
                    'text'          => $this->i18n('suspicious log'),
                    'callback_data' => "/searchSuspiciousIp $search",
                ],
            ];
            $data[] = [
                [
                    'text'          => $this->i18n("clean logs $search"),
                    'callback_data' => "/cleanLogs $search",
                ],
            ];
            if (!empty($fun)) {
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/$fun $page" . ($white ? " $white" : ''),
                    ],
                ];
                $this->update($this->input['from'], $this->input['message_id'], $text, button: $data);
            } else {
                if (empty($this->input['callback_id'])) {
                    $this->delete($this->input['from'], $this->input['message_id']);
                }
                $this->send($this->input['from'], $text, button: $data);
            }
        }
    }

public function searchIp($ip)
    {
        foreach ($this->logs as $v) {
            if ($r = fopen("/logs/$v", 'r')) {
                while (feof($r) === false) {
                    $l = fgets($r);
                    if (preg_match('~' . preg_quote($ip) . '~', $l)) {
                        $res[$v][] = $l;
                    }
                }
                fclose($r);
            }
        }
        if (!empty($res)) {
            foreach ($res as $k => $v) {
                $head= "$k:\n";
                $t = array_chunk($v, 10);
                foreach ($t as $j) {
                    $text = "$head<pre>";
                    foreach ($j as $i) {
                        $text .= htmlspecialchars($i, ENT_HTML5, 'UTF-8');
                    }
                    $text .= '</pre>';
                    $this->send($this->input['from'], $text, $this->input['message_id']);
                }
            }
        } else {
            $this->answer($this->input['callback_id'], 'empty');
        }
    }

public function searchSuspiciousIp($ip)
    {
        $t = [
            $this->suspicious('~\d+\.\d+\.\d+\.\d+.+200\s\d+\s0$~', '/logs/upstream_access', "$ip/32", 'possibly a Reality Degenerate'),
            $this->suspicious($this->reg, '/logs/nginx_default_access', "$ip/32", 'possibly a scanner', true),
            $this->suspicious($this->reg, '/logs/nginx_domain_access', "$ip/32", 'possibly a scanner', true),
        ];
        foreach ($t as $r) {
            if (!empty($r)) {
                foreach ($r as $v) {
                    foreach ($v as $k) {
                        $logs[$k['title']][] = $k['log'];
                    }
                }
            }
        }
        if (!empty($logs)) {
            foreach ($logs as $k => $v) {
                $head= "$k:\n";
                $t = array_chunk($v, 10);
                foreach ($t as $j) {
                    $text = "$head<pre>";
                    foreach ($j as $i) {
                        $text .= htmlspecialchars($i, ENT_HTML5, 'UTF-8');
                    }
                    $text .= '</pre>';
                    $this->send($this->input['from'], $text, $this->input['message_id']);
                }
            }
        } else {
            $this->answer($this->input['callback_id'], 'empty');
        }
    }

public function importIps($type)
    {
        switch ($type) {
            case 'telegram':
                $r = file_get_contents('https://core.telegram.org/resources/cidr.txt');
                if (!empty($r)) {
                    $domains = explode("\n", $r);
                }
                break;
            case 'gcore':
                $r = json_decode(file_get_contents('https://api.gcore.com/cdn/public-ip-list'), true);
                if (!empty($r['addresses'])) {
                    $domains = $r['addresses'];
                }
                break;
            case 'cloudflare':
                $r = json_decode(file_get_contents('https://api.cloudflare.com/client/v4/ips'), true);
                if (!empty($r['result']['ipv4_cidrs'])) {
                    $domains = $r['result']['ipv4_cidrs'];
                }
                break;
        }
        if (!empty($domains = array_filter($domains ?: [], fn($e) => preg_match('~^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/\d{1,2}~', $e)))) {
            $this->addInclude(implode(',', $domains), 'white');
        }
    }

public function denyList($page = 0, $white = 0)
    {
        $text    = 'Menu -> IP -> ' . ($white ? 'ignore' : 'block') . 'list';
        $domains = ($this->getPacConf()[$white ? 'white' : 'deny'] ?? null) ?: [];
        $all     = (int) ceil(count($domains) / $this->limit);
        $page    = min($page, $all - 1);
        $page    = $page < 0 ? $all - 1 : $page;

        if (!empty($white)) {
            $data[] = [
                [
                    'text'          => $this->i18n('telegram IPs'),
                    'callback_data' => "/importIps telegram",
                ],
            ];
            $data[] = [
                [
                    'text'          => $this->i18n('gcore IPs'),
                    'callback_data' => "/importIps gcore",
                ],
            ];
            $data[] = [
                [
                    'text'          => $this->i18n('cloudflare IPs'),
                    'callback_data' => "/importIps cloudflare",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('add'),
                'callback_data' => "/include " . ($white ? 'white' : 'deny'),
            ],
        ];
        if (!empty($domains)) {
            foreach (array_slice($domains, $page * $this->limit, $this->limit) as $v) {
                $data[] = [
                    [
                        'text'          => $v,
                        'callback_data' => "/searchLogs $v denyList $page $white",
                    ],
                    [
                        'text'          => $this->i18n('delete'),
                        'callback_data' => "/allowIp $v $page" . ($white ? " 1" : ''),
                    ],
                ];
            }
            if ($all > 1) {
                $data[] = [
                    [
                        'text'          => '<<',
                        'callback_data' => "/denyList " . ($page - 1 >= 0 ? $page - 1 : $all - 1) . ($white ? " 1" : ' 0'),
                    ],
                    [
                        'text'          => $page + 1,
                        'callback_data' => "/denyList $page" . ($white ? " 1" : ' 0'),
                    ],
                    [
                        'text'          => '>>',
                        'callback_data' => "/denyList " . ($page < $all - 1 ? $page + 1 : 0) . ($white ? " 1" : ' 0'),
                    ],
                ];
            }
            $data[] = [
                [
                    'text'          => $this->i18n('delete all'),
                    'callback_data' => "/cleanDeny" . ($white ? " 1" : ''),
                ],
            ];
        }

        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/ipMenu",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            $text,
            $data ?: false,
        );
    }

public function cleanDeny($white = 0)
    {
        $pac = $this->getPacConf();
        unset($pac[$white ? 'white' : 'deny']);
        $this->setPacConf($pac);
        $this->syncDeny();
        $this->ipMenu();
    }

public function denyIp($ip, $fun = false, $page = 0, $white = 0)
    {
        $pac = $this->getPacConf();
        if (is_array($ip)) {
            foreach ($ip as $v) {
                $pac['deny'][] = $v;
                if (($t = array_search($v, ($pac['white'] ?? null) ?: [])) !== false) {
                    unset($pac['white'][$t]);
                }
            }
        } else {
            $pac['deny'][] = $ip;
            if (($t = array_search($ip, ($pac['white'] ?? null) ?: [])) !== false) {
                unset($pac['white'][$t]);
            }
        }
        $this->setPacConf($pac);
        if (empty($fun)) {
            $this->delete($this->input['from'], $this->input['message_id']);
        }
        $this->syncDeny();
        if (!empty($fun)) {
            $this->{$fun}($page, $white);
        }
    }

public function whiteIp($ip, $fun = false, $page = 0, $white = 0)
    {
        $pac = $this->getPacConf();
        if (is_array($ip)) {
            foreach ($ip as $v) {
                $pac['white'][] = $v;
                if (($t = array_search($v, ($pac['deny'] ?? null) ?: [])) !== false) {
                    unset($pac['deny'][$t]);
                }
            }
        } else {
            $pac['white'][] = $ip;
            if (($t = array_search($ip, ($pac['deny'] ?? null) ?: [])) !== false) {
                unset($pac['deny'][$t]);
            }
        }
        $this->setPacConf($pac);
        if (empty($fun)) {
            $this->delete($this->input['from'], $this->input['message_id']);
        }
        $this->syncDeny();
        if (!empty($fun)) {
            $this->{$fun}($page, $white);
        }
    }

public function allowIp($ip, $page, $white = 0)
    {
        $pac = $this->getPacConf();
        unset($pac[$white ? 'white' : 'deny'][array_search($ip, ($pac[$white ? 'white' : 'deny'] ?? null) ?: [])]);
        $this->setPacConf($pac);
        $this->syncDeny();
        $this->denyList($page, $white);
    }

public function cleanLogs($ip, $nodelete = false)
    {
        foreach ($this->logs as $v) {
            exec("sed -i '/$ip/d' /logs/$v");
        }
        if (empty($nodelete)) {
            $this->delete($this->input['from'], $this->input['message_id']);
        }
    }

public function syncDeny()
    {
        $pac = $this->getPacConf();
        if ($r = fopen('/logs/nginx_tlgrm_access', 'r')) {
            while (feof($r) === false) {
                $l = fgets($r);
                if (preg_match('~(\d+\.\d+\.\d+\.\d+)~', $l, $m)) {
                    $xr[$m[1]] = true;
                }
            }
            fclose($r);
        }
        $text = '';
        if (!empty($xr)) {
            foreach (array_keys($xr) as $v) {
                $text .= "allow $v;\n";
            }
        }
        if (!empty($pac['white'])) {
            $pac['white'] = array_unique($pac['white']);
            sort($pac['white']);
            foreach ($pac['white'] as $v) {
                $text .= "allow $v;\n";
            }
        }
        if (!empty($pac['deny'])) {
            $pac['deny'] = array_unique($pac['deny']);
            sort($pac['deny']);
            foreach ($pac['deny'] as $k => $v) {
                if (!in_array($v, ($pac['white'] ?? null) ?: []) && !in_array($v, array_keys($xr ?? []))) {
                    $text .= "deny $v;\n";
                } else {
                    unset($pac['deny'][$k]);
                }
            }
        }
        $this->setPacConf($pac);
        file_put_contents('/config/deny', $text ?: '');
        $this->ssh('nginx -s reload', 'up');
    }

public function autoScanTimeout()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} send time like 1 hour or 1 day etc",
            $this->input['message_id'],
            reply: 'send time like 1 hour or 1 day etc',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'setAutoScanTimeout',
            'args'           => [],
        ];
    }

public function setAutoScanTimeout($time)
    {
        $pac = $this->getPacConf();
        if (empty($time)) {
            unset($pac['autoscan_timeout']);
            unset($pac['autoscan']);
        } elseif ($t = strtotime($time, 0)) {
            $pac['autoscan_timeout'] = $t;
            $pac['autoscan'] = 1;
        } else {
            $this->send($this->input['from'], "$time - wrong format", $this->input['message_id']);
        }
        $this->setPacConf($pac);
        $this->ipMenu();
    }

}
