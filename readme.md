SBBOT


forked from mercurykd/vpnbot

## Привязка своей группы outbound к конкретному серверу (`~{тег}:outbounds~`)

Если в своём кастомном шаблоне подписки (sing-box, mihomo; xray — пока
по-старому, ждёт своей очереди) нужна отдельная группа, которая ходит только
через определённый сервер (например, отдельный `urltest`/`url-test` для
конкретного сайта через ноду в нужной стране) — используйте плейсхолдер
`~{тег сервера}:outbounds~`.

**1. Узнать тег сервера.**
- Бот: Menu -> Sing-box — там строка `Outbound tag: ~🇩🇪DE:outbounds~` (тег
  считается один раз при первой генерации подписки и больше не меняется).
- Нода: Menu -> Nodes -> нужная нода — там же строка `Node outbound tag:
  ~🇷🇺RU:outbounds~`.

Тег — это флаг + код страны, тот же, что уже виден в реальных outbound'ах
(`🇷🇺RU|Vless` → тег `🇷🇺RU`). Если у нескольких серверов совпадает страна, у
второго и далее — цифра: `🇷🇺RU2`, `🇩🇪DE3` и т.д.

**2. Вписать `~{тег}:outbounds~`** как элемент массива `outbounds` (sing-box)
или `proxies` (mihomo) в своей группе шаблона. На сборке подписки он
разворачивается в реальные теги протоколов этого сервера — Vless/Naive/
Hy2/Anytls у sing-box, Vless/Hy2/Anytls у mihomo (naive там не
поддерживается).

Sing-box, пример — группа "Youtube" только через ноду RU:
```json
{
    "type": "urltest",
    "tag": "Youtube",
    "outbounds": ["~🇷🇺RU:outbounds~"],
    "url": "https://www.gstatic.com/generate_204",
    "interval": "5m"
}
```

Mihomo, тот же смысл:
```json
{
    "name": "Youtube",
    "type": "url-test",
    "proxies": ["~🇷🇺RU:outbounds~"],
    "url": "https://www.gstatic.com/generate_204",
    "interval": 300
}
```

Можно смешивать с обычными тегами в том же массиве:
`"outbounds": ["~🇷🇺RU:outbounds~", "~🇩🇪DE:outbounds~"]` или
`["~🇷🇺RU:outbounds~", "🇩🇪DE|Vless"]`.

Если сервер в момент генерации подписки недоступен/выключен/не
синхронизирован — `~{тег}:outbounds~` просто пропадает из списка (группа
останется с меньшим числом участников), конфиг не ломается.

Ноды, которых нет ни в одной группе шаблона явно, всё равно попадают в
основную группу по умолчанию (`Proxy` — и в sing-box, и в mihomo), без
всякого плейсхолдера. В sing-box это `selector` со вложенной `⚡️Auto`
(`urltest`); в mihomo отдельной auto-группы нет — сам `Proxy` уже
`type: fallback` и сам умеет health-check-выбор.
