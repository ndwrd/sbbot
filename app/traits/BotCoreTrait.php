<?php

trait BotCoreTrait
{
public function input($data = false)
    {
        $this->admin     = false;
        $this->input_raw = $input = $data ?: json_decode(file_get_contents('php://input'), true);
        $this->input     = [
            'message'           => $input['callback_query']['message']['text'] ?? $input['message']['text'] ?? $input['channel_post']['text'] ?? '',
            'message_id'        => $input['callback_query']['message']['message_id'] ?? $input['message']['message_id'] ?? $input['channel_post']['message_id'],
            'chat'              => $input['message']['chat']['id'] ?? $input['callback_query']['message']['chat']['id'] ?? $input['channel_post']['chat']['id'] ?? $input['my_chat_member']['chat']['id'],
            'from'              => $input['message']['from']['id'] ?? $input['inline_query']['from']['id'] ?? $input['callback_query']['from']['id'] ?? $input['channel_post']['chat']['id'] ?? $input['my_chat_member']['from']['id'],
            'username'          => $input['message']['from']['username'] ?? $input['inline_query']['from']['username'] ?? $input['callback_query']['from']['username'],
            'query'             => $input['inline_query']['query'] ?? '',
            'inlid'             => $input['inline_query']['id'] ?? '',
            'group'             => !empty($input['message']['chat']['type']) && 'group' == $input['message']['chat']['type'],
            'sticker_id'        => $input['message']['sticker']['file_id'] ?? false,
            'channel'           => !empty($input['channel_post']['message_id']),
            'callback'          => $input['callback_query']['data'] ?? false,
            'callback_id'       => $input['callback_query']['id'] ?? false,
            'photo'             => $input['message']['photo'] ?? false,
            'file_name'         => $input['message']['document']['file_name'] ?? false,
            'file_id'           => $input['message']['document']['file_id'] ?? false,
            'caption'           => $input['message']['caption'] ?? false,
            'reply'             => $input['message']['reply_to_message']['message_id'] ?? false,
            'reply_from'        => $input['message']['reply_to_message']['from']['id'] ??  $input['callback_query']['message']['reply_to_message']['from']['id'] ?? false,
            'reply_text'        => $input['message']['reply_to_message']['text'] ?? false,
            'new_member_id'     => $input['my_chat_member']['new_chat_member']['user']['id'] ?? false,
            'new_member_status' => $input['my_chat_member']['new_chat_member']['status'] ?? false,
        ];
        $this->auth();
        if ($this->admin) {
            $this->session();
            $this->action();
        }
        $this->callbackCheck();
    }

public function auth()
    {
        $file = dirname(__DIR__) . '/config.php';
        require $file;
        if (empty($c['admin'])) {
            $c['admin'] = [$this->input['from']];
            file_put_contents($file, "<?php\n\n\$c = " . var_export($c, true) . ";\n");
        } elseif (!is_array($c['admin'])) {
            $c['admin'] = [$c['admin']];
            file_put_contents($file, "<?php\n\n\$c = " . var_export($c, true) . ";\n");
        }
        if (in_array($this->input['from'], $c['admin'])) {
            $this->admin = true;
        }
    }

public function callbackCheck()
    {
        if (empty($this->callback) && !empty($this->input['callback_id'])) {
            $this->answer($this->input['callback_id'], $GLOBALS['debug'] ? $this->input['callback'] : false);
        }
    }

public function session()
    {
        session_id($this->input['from']);
        @session_start();
        if (!empty($_SESSION['reply'])) {
            if (empty($this->input['reply'])) {
                foreach ($_SESSION['reply'] as $k => $v) {
                    $this->delete($this->input['chat'], $k);
                }
                unset($_SESSION['reply']);
            }
        }
    }

public function action()
    {
        switch (true) {
            case preg_match('~^/menu$~', $this->input['message'], $m):
            case preg_match('~^/start$~', $this->input['message'], $m):
            case preg_match('~^/menu$~', $this->input['callback'], $m):
            case preg_match('~^/menu (?P<type>adguard|config|ss|lang|domains|nodes)$~', $this->input['callback'], $m):
                $this->menu(type: $m['type'] ?? false, arg: $m['arg'] ?? false);
                break;
            case preg_match('~^/addNode$~', $this->input['callback'], $m):
                $this->addNode();
                break;
            case preg_match('~^/nodeMenu (\w+)$~', $this->input['callback'], $m):
                $this->nodeMenu($m[1]);
                break;
            case preg_match('~^/delNode (\w+)$~', $this->input['callback'], $m):
                $this->delNode($m[1]);
                break;
            case preg_match('~^/delNodeYes (\w+)$~', $this->input['callback'], $m):
                $this->delNodeYes($m[1]);
                break;
            case preg_match('~^/nodeAuthPassword (\w+)$~', $this->input['callback'], $m):
                $this->nodeAuthPassword($m[1]);
                break;
            case preg_match('~^/nodeAuthKey (\w+)$~', $this->input['callback'], $m):
                $this->nodeAuthKey($m[1]);
                break;
            case preg_match('~^/nodeMtproto (\w+)$~', $this->input['callback'], $m):
                $this->nodeMtprotoMenu($m[1]);
                break;
            case preg_match('~^/nodeGenerateSecret (\w+)$~', $this->input['callback'], $m):
                $this->nodeGenerateSecret($m[1]);
                break;
            case preg_match('~^/nodeSetSecret (\w+)$~', $this->input['callback'], $m):
                $this->nodeSetSecret($m[1]);
                break;
            case preg_match('~^/nodeChangeTGDomain (\w+)$~', $this->input['callback'], $m):
                $this->nodeChangeTGDomain($m[1]);
                break;
            case preg_match('~^/nodeDomains (\w+)$~', $this->input['callback'], $m):
                $this->nodeDomains($m[1]);
                break;
            case preg_match('~^/nodeSetDomainDialog (\w+)$~', $this->input['callback'], $m):
                $this->nodeSetDomainDialog($m[1]);
                break;
            case preg_match('~^/nodeAddNip (\w+)$~', $this->input['callback'], $m):
                $this->nodeAddNip($m[1]);
                break;
            case preg_match('~^/nodeToggleOff (\w+)$~', $this->input['callback'], $m):
                $this->nodeToggleOff($m[1]);
                break;
            case preg_match('~^/nodeDelDomain (\w+)$~', $this->input['callback'], $m):
                $this->nodeDelDomain($m[1]);
                break;
            case preg_match('~^/nodeIssueSSL (\w+)$~', $this->input['callback'], $m):
                $this->nodeIssueSSL($m[1]);
                break;
            case preg_match('~^/nodePortsDialog (\w+)$~', $this->input['callback'], $m):
                $this->nodePortsDialog($m[1]);
                break;
            case preg_match('~^/nodeStats (\w+)$~', $this->input['callback'], $m):
                $this->nodeStats($m[1]);
                break;
            case preg_match('~^/nodeRestart (\w+)$~', $this->input['callback'], $m):
                $this->nodeRestart($m[1]);
                break;
            case preg_match('~^/nodeLogs (\w+)$~', $this->input['callback'], $m):
                $this->nodeLogs($m[1]);
                break;
            case preg_match('~^/nodeGetLog (\w+) (\d+)$~', $this->input['callback'], $m):
                $this->nodeGetLog($m[1], $m[2]);
                break;
            case preg_match('~^/nodeClearLog (\w+) (\d+)$~', $this->input['callback'], $m):
                $this->nodeClearLog($m[1], $m[2]);
                break;
            case preg_match('~^/nodeCleanLog (\w+)$~', $this->input['callback'], $m):
                $this->nodeCleanLog($m[1]);
                break;
            case preg_match('~^/nodeSyncUsers (\w+)$~', $this->input['callback'], $m):
                $this->nodeSyncUsers($m[1]);
                break;
            case preg_match('~^/changeTransport(?: (\w+))?$~', $this->input['callback'], $m):
                $this->changeTransport($m[1] ?? false);
                break;
            case preg_match('~^/mainOutbound$~', $this->input['callback'], $m):
                $this->mainOutbound();
                break;
            case preg_match('~^/switchMonthlyStats$~', $this->input['callback'], $m):
                $this->switchMonthlyStats();
                break;
            case preg_match('~^/changePort(?: (\w+))?$~', $this->input['callback'], $m):
                $this->changePort($m[1] ?? null);
                break;
            case preg_match('~^/autoupdate$~', $this->input['callback'], $m):
                $this->autoupdate();
                break;
            case preg_match('~^/ports$~', $this->input['callback'], $m):
                $this->ports();
                break;
            case preg_match('~^/adgFillAllowedClients(?: (\d+))?$~', $this->input['callback'], $m):
                $this->adgFillAllowedClients(($m[1] ?? null) ?: false);
                break;
            case preg_match('~^/offWarp$~', $this->input['callback'], $m):
                $this->offWarp();
                break;
            case preg_match('~^/id$~', $this->input['message'], $m):
                $this->send($this->input['chat'], "your id: {$this->input['from']}\nchat id: {$this->input['chat']}", $this->input['message_id']);
                break;
            case preg_match('~^/adguardChBr$~', $this->input['callback'], $m):
                $this->adguardChBr();
                break;
            case preg_match('~^/mtproto$~', $this->input['callback'], $m):
                $this->mtproto();
                break;
            case preg_match('~^/deleteAll (\w+)$~', $this->input['callback'], $m):
                $this->deleteAll($m[1]);
                break;
            case preg_match('~^/exportList (\w+)$~', $this->input['callback'], $m):
                $this->exportList($m[1]);
                break;
            case preg_match('~^/hidePort (\w+)$~', $this->input['callback'], $m):
                $this->hidePort($m[1]);
                break;
            case preg_match('~^/deleteYes (\w+)$~', $this->input['callback'], $m):
                $this->deleteYes($m[1]);
                break;
            case preg_match('~^/applyupdatebot$~', $this->input['callback'], $m):
                $this->applyupdatebot();
                break;
            case preg_match('~^/restart$~', $this->input['callback'], $m):
                $this->restart();
                break;
            case preg_match('~^/logs$~', $this->input['callback'], $m):
                $this->logs();
                break;
            case preg_match('~^/dnstt$~', ($this->input['callback'] ?? null) ?: $this->input['message'], $m):
                $this->dnstt(!empty($this->input['callback']));
                break;
            case preg_match('~^/dnsttDownload$~', $this->input['callback'], $m):
                $this->dnsttDownload();
                break;
            case preg_match('~^/dnsttDomain$~', $this->input['callback'], $m):
                $this->dnsttDomain();
                break;
            case preg_match('~^/dnsttPassword$~', $this->input['callback'], $m):
                $this->dnsttPassword();
                break;
            case preg_match('~^/setdnsttDomain (\w+)$~', $this->input['callback'], $m):
                $this->setdnsttDomain($m[1]);
                break;
            case preg_match('~^/setdnsttPassword (\w+)$~', $this->input['callback'], $m):
                $this->setdnsttPassword($m[1]);
                break;
            case preg_match('~^/getLog (?P<arg>\d+(?:_(?:-)?\d+)?)$~', $this->input['callback'], $m):
                $this->getLog(...explode('_', $m['arg']));
                break;
            case preg_match('~^/clearLog (?P<arg>\d+(?:_(?:-)?\d+)?)$~', $this->input['callback'], $m):
                $this->clearLog(...explode('_', $m['arg']));
                break;
            case preg_match('~^/cleanLog$~', $this->input['callback'], $m):
                $this->cleanLog();
                break;
            case preg_match('~^/delLog (?P<arg>\d+(?:_(?:-)?\d+)?)$~', $this->input['callback'], $m):
                $this->delLog(...explode('_', $m['arg']));
                break;
            case preg_match('~^/debug$~', $this->input['message'], $m):
                $this->debug();
                break;
            case preg_match('~^/backup$~', $this->input['callback'], $m):
                $this->backup();
                break;
            case preg_match('~^/generateSecret$~', $this->input['callback'], $m):
                $this->generateSecret();
                break;
            case preg_match('~^/setSecret$~', $this->input['callback'], $m):
                $this->setSecret();
                break;
            case preg_match('~^/selfssl$~', $this->input['callback'], $m):
                $this->selfssl();
                break;
            case preg_match('~^/addXrUser$~', $this->input['callback'], $m):
                $this->addXrUser();
                break;
            case preg_match('~^/renameXrUser (\d+)$~', $this->input['callback'], $m):
                $this->renameXrUser($m[1]);
                break;
            case preg_match('~^/resetXrUser (\d+)$~', $this->input['callback'], $m):
                $this->resetXrUser($m[1]);
                break;
            case preg_match('~^/resetXrStats$~', $this->input['callback'], $m):
                $this->resetXrStats();
                break;
            case preg_match('~^/checkdns$~', $this->input['callback'], $m):
                $this->checkdns();
                break;
            case preg_match('~^/adguardpsswd$~', $this->input['callback'], $m):
                $this->adguardpsswd();
                break;
            case preg_match('~^/setAdguardKey$~', $this->input['callback'], $m):
                $this->setAdguardKey();
                break;
            case preg_match('~^/addadmin$~', $this->input['callback'], $m):
                $this->enterAdmin();
                break;
            case preg_match('~^/enterPage$~', $this->input['callback'], $m):
                $this->enterPage();
                break;
            case preg_match('~^/adguardreset$~', $this->input['callback'], $m):
                $this->adguardreset();
                break;
            case preg_match('~^/addupstream$~', $this->input['callback'], $m):
                $this->addupstream();
                break;
            case preg_match('~^/setSSL (\w+)$~', $this->input['callback'], $m):
                $this->setSSL($m[1]);
                break;
            case preg_match('~^/lang (\w+)$~', $this->input['callback'], $m):
                $this->setLang($m[1]);
                break;
            case preg_match('~^/deletessl$~', $this->input['callback'], $m):
                $this->deleteSSL();
                break;
            case preg_match('~^/dw (\w+) (\w+)$~', $this->input['callback'], $m):
                $this->dw($m[1], $m[2]);
                break;
            case preg_match('~^/userXr (\d+)$~', $this->input['callback'], $m):
                $this->userXr($m[1]);
                break;
            case preg_match('~^/choiceTemplate (.+)$~', $this->input['callback'], $m):
                $this->choiceTemplate($m[1]);
                break;
            case preg_match('~^/templateUser (\w+) (\d+)$~', $this->input['callback'], $m):
                $this->templateUser($m[1], $m[2]);
                break;
            case preg_match('~^/timerXr (\d+)$~', $this->input['callback'], $m):
                $this->timerXr($m[1]);
                break;
            case preg_match('~^/limitXr (\d+)$~', $this->input['callback'], $m):
                $this->limitXr($m[1]);
                break;
            case preg_match('~^/switchXr (\d+)$~', $this->input['callback'], $m):
                $this->switchXr($m[1]);
                break;
            case preg_match('~^/delxr (\d+)$~', $this->input['callback'], $m):
                $this->delxr($m[1]);
                break;
            case preg_match('~^/listXr (\d+)$~', $this->input['callback'], $m):
                $this->listXr($m[1]);
                break;
            case preg_match('~^/deladmin (\d+)$~', $this->input['callback'], $m):
                $this->delAdmin($m[1]);
                break;
            case preg_match('~^/qrVless (\d+)(?:_(\d+))?$~', $this->input['callback'], $m):
                $this->qrVless($m[1], ($m[2] ?? null) ?: false);
                break;
            case preg_match('~^/qrMtproto$~', $this->input['callback'], $m):
                $this->qrMtproto();
                break;
            case preg_match('~^/delupstream (\d+)$~', $this->input['callback'], $m):
                $this->delupstream($m[1]);
                break;
            case preg_match('~^/deldomain$~', $this->input['callback'], $m):
                $this->delDomain();
                break;
            case preg_match('~^/addNipdomain$~', $this->input['callback'], $m):
                $this->addNipdomain();
                break;
            case preg_match('~^/(?P<action>change|delete)(?P<typelist>\w+) (?P<arg>\d+)(?: (?P<page>\d+))?$~', $this->input['callback'], $m):
                $this->listPacChange($m['typelist'], $m['action'], $m['arg'], ($m['page'] ?? null) ?: 0);
                break;
            case preg_match('~^/domain$~', $this->input['callback'], $m):
                $this->domain();
                break;
            case preg_match('~^/warp$~', $this->input['callback'], $m):
                $this->warp();
                break;
            case preg_match('~^/warpPlus$~', $this->input['callback'], $m):
                $this->warpPlus();
                break;
            case preg_match('~^/singbox(?: (\d+))?$~', $this->input['callback'], $m):
                $this->singbox(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/templatesMenu$~', $this->input['callback'], $m):
                $this->templatesMenu();
                break;
            case preg_match('~^/statsMenu$~', $this->input['callback'], $m):
                $this->statsMenu();
                break;
            case preg_match('~^/xtlsblock(?: (\d+))?$~', $this->input['callback'], $m):
                $this->xtlsblock(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/routes(?: (\d+))?$~', $this->input['callback'], $m):
                $this->routes(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/xtlswarp(?: (\d+))?$~', $this->input['callback'], $m):
                $this->xtlswarp(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/xtlsproxy(?: (\d+))?$~', $this->input['callback'], $m):
                $this->xtlsproxy(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/xtlsapp(?: (\d+))?$~', $this->input['callback'], $m):
                $this->xtlsapp(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/xtlsprocess(?: (\d+))?$~', $this->input['callback'], $m):
                $this->xtlsprocess(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/xtlssubnet(?: (\d+))?$~', $this->input['callback'], $m):
                $this->xtlssubnet(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/xtlsrulesset(?: (\d+))?$~', $this->input['callback'], $m):
                $this->xtlsrulesset(($m[1] ?? null) ?: 0);
                break;
            case preg_match('~^/templateCopy (\w+)(?: (.+))?$~', $this->input['callback'], $m):
                $this->templateCopy($m[1], $m[2]);
                break;
            case preg_match('~^/delTemplate (\w+)(?: (.+))?$~', $this->input['callback'], $m):
                $this->delTemplate($m[1], $m[2]);
                break;
            case preg_match('~^/downloadOrigin (\w+)$~', $this->input['callback'], $m):
                $this->downloadOrigin($m[1]);
                break;
            case preg_match('~^/downloadTemplate (\w+)(?: (.+))?$~', $this->input['callback'], $m):
                $this->downloadTemplate($m[1], $m[2]);
                break;
            case preg_match('~^/defaultTemplate (\w+)(?: (.+))?$~', $this->input['callback'], $m):
                $this->defaultTemplate($m[1], $m[2]);
                break;
            case preg_match('~^/templates (\w+)$~', $this->input['callback'], $m):
                $this->templates($m[1]);
                break;
            case preg_match('~^/templateAdd (\w+)$~', $this->input['callback'], $m):
                $this->templateAdd($m[1]);
                break;
            case preg_match('~^/changeFakeDomain$~', $this->input['callback'], $m):
                $this->changeFakeDomain();
                break;
            case preg_match('~^/autoCleanLogs$~', $this->input['callback'], $m):
                $this->autoCleanLogs();
                break;
            case preg_match('~^/selfFakeDomain$~', $this->input['callback'], $m):
                $this->selfFakeDomain();
                break;
            case preg_match('~^/changeTGDomain$~', $this->input['callback'], $m):
                $this->changeTGDomain();
                break;
            case preg_match('~^/setTGAdtag$~', $this->input['callback'], $m):
                $this->changeTGAdtag();
                break;
            case preg_match('~^/include (\w+)$~', $this->input['callback'], $m):
                $this->include($m[1]);
                break;
            case preg_match('~^/addOverrideHtml$~', $this->input['callback'], $m):
                $this->addOverrideHtml();
                break;
            case preg_match('~^/export$~', $this->input['callback'], $m):
                $this->pinBackup();
                break;
            case preg_match('~^/import$~', $this->input['callback'], $m):
                $this->import();
                break;
            case preg_match('~^/importList (\w+)$~', $this->input['callback'], $m):
                $this->importList($m[1]);
                break;
            case !empty($this->input['reply']):
                $this->reply();
                break;
        }
    }

public function collectSession() {
        $p = $this->getSingboxStats();
        $p['global'] = [
            'download' => $p['global']['download'] + $p['session']['download'],
            'upload'   => $p['global']['upload'] + $p['session']['upload'],
        ];
        $p['session'] = [
            'download' => 0,
            'upload'   => 0,
        ];
        foreach ($p['users'] as $k => $v) {
            $p['users'][$k]['global']['download']  += $v['session']['download'];
            $p['users'][$k]['session']['download']  = 0;
            $p['users'][$k]['global']['upload']    += $v['session']['upload'];
            $p['users'][$k]['session']['upload']    = 0;
        }
        foreach ($p['inbounds'] ?? [] as $k => $v) {
            $p['inbounds'][$k]['global']['download'] = ($v['global']['download'] ?? 0) + ($v['session']['download'] ?? 0);
            $p['inbounds'][$k]['session']['download'] = 0;
            $p['inbounds'][$k]['global']['upload']   = ($v['global']['upload'] ?? 0) + ($v['session']['upload'] ?? 0);
            $p['inbounds'][$k]['session']['upload']   = 0;
        }
        $this->setSingboxStats($p);
    }

public function setLang($lang)
    {
        $conf = $this->getPacConf();
        $this->language = $conf['language'] = $lang;
        $this->setPacConf($conf);
        $this->menu('config');
    }

public function cron()
    {
        $period = 10;
        while (true) {
            $this->shutdownClientXr();
            $this->checkVersion();
            $this->checkBackup();
            $this->checkLogs();
            $this->checkResetSingboxStats();
            $this->checkCert();
            $this->singboxStatsUser();
            $this->checkNodeProvisioning();
            sleep($period);
        }
    }

public function cleanQueue(): void
    {
        $r = $this->request('deleteWebhook', []);
        $r = $this->request('getUpdates', ['offset' => -1]);
    }

public function checkVersion()
    {
        try {
            require dirname(__DIR__) . '/config.php';
            if (!empty($c['admin']) && (empty($this->time) || ((time() - $this->time) > 3600))) {
                $this->time = time();
                $current    = file_get_contents('/version');
                $b          = exec('git -C / rev-parse --abbrev-ref HEAD');
                $last       = file_get_contents("https://raw.githubusercontent.com/ndwrd/sbbot/$b/version");
                if (!empty($last) && $last != $this->last && $last != $current) {
                    $this->last = $last;
                    $diff       = array_slice(explode("\n", $last), 0, count(explode("\n", $last)) - count(explode("\n", $current)));
                    $diff       = array_slice($diff, 0, 10);
                    if (!empty($diff)) {
                        exec('git -C / fetch');
                        foreach ($c['admin'] as $k => $v) {
                            $this->send($v, implode("\n", $diff), 0, [
                                [
                                    [
                                        'text'    => 'Changelog',
                                        'web_app' => ['url' => "https://raw.githubusercontent.com/ndwrd/sbbot/$b/version"],
                                    ],
                                    [
                                        'text'          => $this->i18n('update bot'),
                                        'callback_data' => "/applyupdatebot",
                                    ],
                                ]
                            ]);
                        }
                        if ($this->getPacConf()['autoupdate']) {
                            $this->input['chat'] = $this->input['from'] = $c['admin'][0];
                            $this->applyupdatebot();
                        }
                    }
                }
            }
        } catch (Exception $e) {
        }
    }

public function getTime(int $seconds)
    {
        $seconds = ($seconds - time()) > 0 ? $seconds - time() : 0;
        $items   = [
            'Y' => [
                'diff' => 1970,
                'sign' => 'y',
            ],
            'm' => [
                'diff' => 1,
                'sign' => 'mon',
            ],
            'd' => [
                'diff' => 1,
                'sign' => 'd',
            ],
            'H' => [
                'diff' => 0,
                'sign' => 'h',
            ],
            'i' => [
                'diff' => 0,
                'sign' => 'min',
            ],
            's' => [
                'diff' => 0,
                'sign' => 's',
            ],
        ];
        $text = '';
        foreach ($items as $k => $v) {
            if (($t = gmdate($k, $seconds) - $v['diff']) > 0) {
                $text .= " $t{$v['sign']}";
                if (!empty($i)) {
                    break;
                }
                $i++;
            }
        }
        return trim($text) ?: '♾';
    }

public function reply()
    {
        if (!empty($_SESSION['reply'][$this->input['reply']])) {
            $this->delete($this->input['chat'], $this->input['reply']);
            $this->delete($this->input['chat'], $this->input['message_id']);
            $callback = $_SESSION['reply'][$this->input['reply']]['callback'];
            $this->input['message_id'] = $this->input['callback_id'] = $_SESSION['reply'][$this->input['reply']]['start_message'];
            $this->{$callback}($this->input['message'], ...$_SESSION['reply'][$this->input['reply']]['args']);
            $this->answer($_SESSION['reply'][$this->input['reply']]['start_message']);
            unset($_SESSION['reply'][$this->input['reply']]);
        }
    }

public function enterPage()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter limit on page",
            $this->input['message_id'],
            reply: 'enter limit on page',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setPage',
            'args'          => [],
        ];
    }

public function setPage($text) {
        $c = $this->getPacConf();
        $c['limitpage'] = (int) $text;
        $this->setPacConf($c);
        $this->menu('config');
    }

public function i18n(string $menu): string
    {
        return ($this->i18n[$menu][$this->language] ?? null) ?: $menu;
    }

public function alignColumns(array $columns): string
    {
        $columnLengths = [];
        foreach ($columns as $column) {
            $maxLength = 0;
            foreach ($column as $cell) {
                $len = mb_strlen($cell, 'UTF-8');
                $maxLength = max($maxLength, $len);
            }
            $columnLengths[] = $maxLength;
        }

        $rowCount = count($columns[0]);
        $columnCount = count($columns);

        $result = [];
        for ($row = 0; $row < $rowCount; $row++) {
            $line = '';
            for ($col = 0; $col < $columnCount; $col++) {
                $cell = $columns[$col][$row];
                $padding = str_repeat(' ', $columnLengths[$col] - mb_strlen($cell, 'UTF-8'));
                $line .= $cell . $padding;

                if ($col < $columnCount - 1) {
                    $line .= '  ';
                }
            }
            $result[] = $line;
        }

        return implode("\n", $result);
    }

public function menu($type = false, $arg = false, $return = false)
    {
        $conf   = $this->getPacConf();
        $domain = ($conf['domain'] ?? null) ?: $this->ip;
        $hash   = $this->getHashBot();
        if ($type == false) {
            $update = exec('git -C / rev-list --count HEAD..@{u}');
            $branch = exec('git -C / rev-parse --abbrev-ref HEAD');
            $backup = array_filter(explode('/', $conf['backup'] ?? ''));
            if (!empty($backup)) {
                if (!empty(strtotime($backup[0])) && !empty(strtotime($backup[1]))) {
                    $backup = "{$backup[0]} start / {$backup[1]} period";
                } else {
                    $backup = "{$conf['backup']} - wrong format";
                }
            }
            $cron   = $this->dontshowcron ? '' : $this->i18n($this->ssh('pgrep -f cron.php', 'service') ? 'on' : 'off') . ' cron';
            $main[] = 'v' . getenv('VER') . " $branch" . ($update ? ' (have updates)' : '');

            if (!empty($conf['domain'])) {
                $main[] = '';
                if (!empty($conf['domain'])) {
                    $ssl_expiry = $this->expireCert();
                    $certs      = $this->domainsCert() ?: [];

                    $main[] = "<blockquote>";
                    $main[] = "<b>Domains:</b>";
                    $main[] = "General: {$conf['domain']}";
                    if (!empty($conf['naiveSubdomain'])) {
                        $main[] = "Naive: {$conf['naiveSubdomain']}.{$conf['domain']}";
                    }
                    if (!empty($conf['anytlsSubdomain'])) {
                        $main[] = "Anytls: {$conf['anytlsSubdomain']}.{$conf['domain']}";
                    }
                    if (!empty($conf['adguardkey'])) {
                        $main[] = "{$conf['adguardkey']}.{$conf['domain']} adguard DOT";
                    }
                    if (in_array($conf['domain'], $certs)) {
                        $main[] = "SSL: " . date('Y-m-d H:i:s', $ssl_expiry);
                    }
                    $main[] = "</blockquote>";
                } else {
                    $main[] = $this->i18n('domain explain');
                }
            }


            $ports  = $this->getPorts();

            $main[] = '<code>';
            $main[] = $this->alignColumns([
                [
                    $this->i18n($this->ssh('pgrep sing-box', 'sbx') ? 'on' : 'off') . ' ' . $this->i18n('vless'),
                    $this->i18n($this->ssh('pgrep mtproto-proxy', 'tg') ? 'on' : 'off') . ' ' . $this->i18n('mtproto'),
                    $this->i18n(exec("JSON=1 timeout 2 dnslookup google.com ad") ? 'on' : 'off') . ' ' . $this->i18n('ad_title'),
                    $this->i18n($this->warpStatus() == 'on' ? 'on' : 'off') . ' ' . $this->i18n('warp'),
                ],
                [
                    $this->i18n('on') . ' 443',
                    $this->i18n($ports['tg']['enable'] ? 'on' : 'off') . ($ports['tg']['enable'] ? ' ' . $ports['tg']['port'] : 'port unavailable'),
                    $this->i18n($ports['ad']['enable'] ? 'on' : 'off') . ($ports['ad']['enable'] ? ' ' . $ports['ad']['port'] : 'port unavailable'),
                    '',
                ],
            ]);
            $main[] = '';
            $main[] = $this->alignColumns([
                [
                    $this->i18n($backup ? 'on' : 'off') . ' autobackup',
                    $this->i18n(!empty($conf['reset_monthly']) ? 'on' : 'off') . ' autoreset',
                ],
                [
                    $this->i18n(!empty($conf['autoupdate']) ? 'on' : 'off') . ' autoupdate',
                    $cron,
                ],
            ]);
            $main[] = '</code>';

        }
        $menu   = [
            'main' => [
                'text' => implode("\n", $main ?: []),
                'data' => array_merge(
                    [
                        [
                            [
                                'text'          => $this->i18n('vless'),
                                'callback_data' => "/singbox",
                            ],
                            [
                                'text'          => $this->i18n('mtproto'),
                                'callback_data' => "/mtproto",
                            ],
                        ],
                        [
                            [
                                'text'          => $this->i18n('ad_title'),
                                'callback_data' => "/menu adguard",
                            ],
                        ],
                        [
                            [
                                'text'          => $this->i18n('nodes'),
                                'callback_data' => "/menu nodes",
                            ],
                        ],
                        [
                            [
                                'text'          => $this->i18n('config'),
                                'callback_data' => "/menu config",
                            ],
                        ],
                    ]
                )
            ],
            'adguard'      => $type == 'adguard' ? $this->adguardMenu()                    : false,
            'config'       => $type == 'config'  ? $this->configMenu()                     : false,
            'lang'         => $type == 'lang'    ? $this->menuLang()                       : false,
            'domains'      => $type == 'domains' ? $this->domainsMenu()                    : false,
            'nodes'        => $type == 'nodes'   ? $this->nodesMenu()                      : false,
        ];

        $text = $menu[$type ?: 'main' ]['text'];
        $data = $menu[$type ?: 'main' ]['data'];

        if (empty($type) && $update) {
            $b = exec('git -C / rev-parse --abbrev-ref HEAD');
            array_unshift($data, [
                [
                    'text'    => 'Changelog',
                    'web_app' => ['url' => "https://raw.githubusercontent.com/ndwrd/sbbot/$b/version"],
                ],
                [
                    'text'          => $this->i18n('update bot'),
                    'callback_data' => "/applyupdatebot",
                ],
            ]);
        }

        if ($return) {
            return [$text, $data];
        }

        if (!empty($this->input['callback_id'])) {
            $this->update(
                $this->input['chat'],
                $this->input['message_id'],
                $text,
                $data ?: false,
            );
        } else {
            $this->send(
                $this->input['chat'],
                $text,
                $this->input['message_id'],
                $data ?: false,
            );
        }
    }

public function dockerApi($url, $method = 'GET', $data = [])
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_POSTFIELDS       => !empty($data) ? json_encode($data) : null,
            CURLOPT_URL              => "http://localhost$url",
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock'
        ]);
        $r = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $r;
    }

public function restartContainer($service)
    {
        $r = $this->dockerApi('/containers/json?all=1');
        foreach ($r as $v) {
            if (($v['Labels']['com.docker.compose.service'] ?? '') == $service) {
                $this->dockerApi("/containers/{$v['Id']}/restart", 'POST');
                break;
            }
        }
    }

public function cleanDocker()
    {
        $r = $this->dockerApi('/images/json');
        foreach ($r as $v) {
            if (!empty($v['RepoTags'])) {
                foreach ($v['RepoTags'] as $j) {
                    if (preg_match('~^ghcr\.io/ndwrd/sbbot/~', $j)) {
                        $i[] = $v['Id'];
                        break;
                    }
                }
            }
        }
        $r = $this->dockerApi('/containers/json?all=1');
        foreach ($r as $v) {
            if (preg_match('~^ghcr\.io/ndwrd/sbbot/~', $v['Image'])) {
                $c[] = $v['ImageID'];
            }
        }
        if (!empty($d = array_diff($i, $c))) {
            foreach ($d as $v) {
                $this->dockerApi("/images/$v", 'DELETE');
            }
        }
        $this->dockerApi('/images/prune', 'POST', ['dangling' => true]);
        $this->dockerApi('/build/prune', 'POST');
    }

public function getBytes($bytes)
    {
        $t = [
            'B',
            'KB',
            'MB',
            'GB',
            'TB',
        ];
        foreach ($t as $k => $v) {
            if ($k == 0) {
                continue;
            }
            if ($bytes / (1024 ** $k) < 1) {
                return round($bytes / (1024 ** ($k - 1)), 2) . " {$t[$k - 1]}";
            }
        }
    }

public function getMB($bytes)
    {
        return round(($bytes ?: 0) / (1024 ** 2), 2) . ' MB';
    }

public function getHostStats()
    {
        // Как в 3x-ui/2s-ui: CPU/MEM/DISK хоста, а не контейнера. На обычном (без
        // lxcfs) docker-хосте /proc/stat и /proc/meminfo внутри контейнера — это
        // /proc самого хоста целиком, не cgroup-урезанный вид, так что два сэмпла
        // с паузой достаточно для CPU%. Диск смотрим через /app — это bind-mount
        // с хоста (docker-compose.yml), поэтому df тут отдаёт реальный раздел
        // хоста, а не overlay-fs контейнера.
        $sample = function () {
            $line  = trim(strtok(file_get_contents('/proc/stat'), "\n"));
            $parts = array_map('intval', preg_split('~\s+~', $line));
            array_shift($parts);
            return $parts;
        };
        $a = $sample();
        usleep(200000);
        $b = $sample();
        $totalDelta = array_sum($b) - array_sum($a);
        $idleDelta  = ($b[3] + ($b[4] ?? 0)) - ($a[3] + ($a[4] ?? 0));
        $cpu        = $totalDelta > 0 ? round((1 - $idleDelta / $totalDelta) * 100, 1) : 0;

        $mem = [];
        foreach (explode("\n", file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('~^(\w+):\s+(\d+)~', $line, $m)) {
                $mem[$m[1]] = (int) $m[2];
            }
        }
        $memTotal     = $mem['MemTotal'] ?? 0;
        $memAvailable = $mem['MemAvailable'] ?? ($mem['MemFree'] ?? 0);
        $memPercent   = $memTotal > 0 ? round((1 - $memAvailable / $memTotal) * 100, 1) : 0;

        $diskTotal   = @disk_total_space('/app') ?: 0;
        $diskFree    = @disk_free_space('/app') ?: 0;
        $diskPercent = $diskTotal > 0 ? round((1 - $diskFree / $diskTotal) * 100, 1) : 0;

        return ['cpu' => $cpu, 'mem' => $memPercent, 'disk' => $diskPercent];
    }

public function formatUptime($seconds)
    {
        $seconds = (int) $seconds;
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        return "{$d}D {$h}H {$m}M";
    }

public function getHashBot($notset = false)
    {
        $p = $this->getPacConf();
        if (!empty($p['hashbot'])) {
            return $p['hashbot'];
        }
        $p['hashbot'] = substr(hash('sha256', $this->key), 0, 8);
        if (empty($notset)) {
            $this->setPacConf($p);
        }
        return $p['hashbot'];
    }

public function debug()
    {
        $file = dirname(__DIR__) . '/config.php';
        require $file;
        $c['debug'] = !$c['debug'];
        file_put_contents($file, "<?php\n\n\$c = " . var_export($c, true) . ";\n");
        $this->menu('config');
    }

public function autoupdate()
    {
        $p = $this->getPacConf();
        $p['autoupdate'] = !$p['autoupdate'];
        $this->setPacConf($p);
        $this->menu('config');
    }

public function ssh($cmd, $service = 'service', $wait = true, $log = '/dev/null', $host = null)
    {
        // $host — контейнер-таргет живёт не в нашей docker-network, а на удалённой
        // ноде: прямого коннекта к hostname контейнера там нет, поэтому вместо
        // ssh2_connect($service) идём на host ноды (тот же ключ, что и везде —
        // main-провижининг ставит его в authorized_keys ноды один раз). Если
        // $service задан — просим её docker поднять команду в нужном контейнере;
        // $service = null — команда идёт прямо на хост ноды (restart/ports/
        // certbot — то, что живёт вне контейнеров, как /update/pipe или
        // docker-compose.override.yml).
        $target = $host ?: $service;
        if ($host && $service) {
            $cmd = 'docker exec ' . escapeshellarg($service) . ' sh -c ' . escapeshellarg($cmd);
        }
        try {
            $c = ssh2_connect($target, 22);
            if (empty($c)) {
                throw new Exception("no connection to $target: \n$cmd\n" . var_export($c, true));
            }
            $a = ssh2_auth_pubkey_file($c, 'root', '/ssh/key.pub', '/ssh/key');
            if (empty($a)) {
                throw new Exception("auth fail: \n$cmd\n" . var_export($a, true));
            }

            if (!$wait) {
                $cmd = "nohup sh -c \"$cmd 2>&1 | tee -a $log >&3\" 3>/proc/1/fd/1 </dev/null &";
            }

            $s = ssh2_exec($c, $cmd);
            if (empty($s)) {
                throw new Exception("exec fail: \n$cmd\n" . var_export($s, true));
            }

            $data = "";
            if ($wait) {
                stream_set_blocking($s, true);
                while ($buf = fread($s, 4096)) {
                    $data .= $buf;
                }
            } else {
                stream_set_blocking($s, false);
                usleep(100000);
            }

            fclose($s);
            ssh2_disconnect($c);
        } catch (Exception | Error $e) {
            if (!empty($GLOBALS['debug'])) {
                $this->send($this->input['chat'], $e->getMessage(), $this->input['message_id']);
            }
        }
        return $data;
    }

public function polling()
    {
        $offset = -1;
        while (true) {
            $r = $this->request('getUpdates', [
                'offset'  => $offset,
                'limit'   => 3,
                'timeout' => 5,
            ]);
            if (!empty($r['description'])) {
                error_log('getUpdates error: ' . $r['description']);
                sleep(3);
                continue;
            }
            file_put_contents('/start', 1);
            if (!empty($r['result'])) {
                foreach ($r['result'] as $v) {
                    try {
                        $this->input($v);
                    } catch (Throwable $e) {
                        error_log($e);
                    } finally {
                        session_write_close();
                    }
                    $offset = max($offset, $v['update_id']);
                }
                $offset++;
            } else {
                sleep(1);
            }
        }
    }
}
