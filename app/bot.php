<?php

require_once __DIR__ . '/traits/BotCoreTrait.php';
require_once __DIR__ . '/traits/BotMtprotoTrait.php';
require_once __DIR__ . '/traits/BotSingboxTrait.php';
require_once __DIR__ . '/traits/BotPacTrait.php';
require_once __DIR__ . '/traits/BotAdminSettingsTrait.php';
require_once __DIR__ . '/traits/BotDomainSslTrait.php';
require_once __DIR__ . '/traits/BotTelegramTrait.php';
require_once __DIR__ . '/traits/BotDnsttTrait.php';
require_once __DIR__ . '/traits/BotWarpTrait.php';
require_once __DIR__ . '/traits/BotNodeTrait.php';

class Bot
{
    use BotCoreTrait;
    use BotMtprotoTrait;
    use BotSingboxTrait;
    use BotPacTrait;
    use BotAdminSettingsTrait;
    use BotDomainSslTrait;
    use BotTelegramTrait;
    use BotDnsttTrait;
    use BotWarpTrait;
    use BotNodeTrait;


    public $input;
    public $update;
    public $ip;
    public $limit;
    public $key;
    public $file;
    public $api;
    public $pac;
    public $pacCache;
    public $i18n;
    public $language;
    public $selfupdate;
    public $last;
    public $dontshowcron;
    public $input_raw;
    public $time;
    public $time2;
    public $time_singbox_stats;
    public $time_node_cert;
    public $time_apps_cache;
    public $time_apps_ttl;
    public $appsCache;
    public $admin;
    public $ports;
    public $time_nodes_status;

    public function __construct($key, $i18n)
    {
        $api = getenv('TELEGRAM_API') ?: 'api.telegram.org';
        $this->key      = $key;
        $this->api      = "https://$api/bot$key/";
        $this->file     = "https://$api/file/bot$key/";
        $this->pac      = '/config/pac.json';
        $this->appsCache = '/config/apps_cache.json';
        $this->ip       = getenv('IP');
        $this->i18n     = $i18n;
        $this->language = ($this->getPacConf()['language'] ?? null) ?: 'en';
        $this->limit    = ($this->getPacConf()['limitpage'] ?? null) ?: 10;
        $this->update   = '/update/json';
        $this->ports = [
            'tg'    => '443',
            'dnstt' => '53/udp',
        ];
    }
}
