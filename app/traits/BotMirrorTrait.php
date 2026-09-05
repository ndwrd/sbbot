<?php

trait BotMirrorTrait
{
public function mirrorMenu()
    {
        $ip     = $this->getPacConf()['domain'] ?: $this->ip;
        $text[] = "Menu -> Mirror";
        $text[] = <<<PNG
                    <pre>client -> intermediate VPS -> vpnbot
                                         ^           |
                                         |  install  |
                                         |  mirror   |
                                          -----------
                    </pre>
                    PNG;
        $data[] = [
            [
                'text'          => $this->i18n('download'),
                'callback_data' => "/getMirror",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu",
            ],
        ];
        return [
            'text' => implode("\n", $text),
            'data' => $data,
        ];
    }

public function getMirror()
    {
        $s = file_get_contents('/mirror/start_socat.sh');
        $p = $this->getPorts();
        $t = str_replace([
            '~ip~',
            '~tg~',
        ], [
            getenv('IP'),
            $p['tg']['port'],
        ], $s);
        $this->sendFile($this->input['from'], new CURLStringFile($t, 'socat.sh', 'application/x-sh'));
    }
}
