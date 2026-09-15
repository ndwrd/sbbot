SBBOT


**forked from mercurykd/vpnbot**

## Установка

На чистом сервере Ubuntu/Debian, от root:

```bash
wget -O- https://raw.githubusercontent.com/ndwrd/sbbot/main/scripts/init.sh | sh -s YOUR_TELEGRAM_BOT_KEY
```

`YOUR_TELEGRAM_BOT_KEY` — токен бота от [@BotFather](https://t.me/BotFather).
Скрипт ставит Docker, клонирует репозиторий в `~/sbbot` и поднимает контейнеры.
После запуска напишите боту `/start`.

Переезд с vpnbot — см. раздел в [user_guide.md](user_guide.md#переезд-с-vpnbot).

Руководство по настройке подписок (кастомные группы outbound по серверу,
подписка для Happ/INCY и т.д.) — см. [user_guide.md](user_guide.md).
