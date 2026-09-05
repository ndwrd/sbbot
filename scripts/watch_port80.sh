#!/bin/sh
# Держит 80/tcp в ufw закрытым, кроме момента выпуска/перевыпуска SSL:
# setSSL() (php-контейнер) создаёт/удаляет ./certs/.want_port80 вокруг вызова
# certbot, а этот скрипт на хосте следит за файлом и сам дёргает ufw — контейнер
# не может напрямую менять firewall хоста, только общую bind-mount папку ./certs/.
cd "$(dirname "$0")/.." || exit 1
DIR="./certs"
NAME=".want_port80"
STATE=0

sync_state() {
    if [ -f "$DIR/$NAME" ] && [ "$STATE" -eq 0 ]; then
        ufw allow 80/tcp
        STATE=1
    elif [ ! -f "$DIR/$NAME" ] && [ "$STATE" -eq 1 ]; then
        ufw delete allow 80/tcp
        STATE=0
    fi
}

sync_state
inotifywait -m -q -e create -e delete -e moved_to -e moved_from --format '%f' "$DIR" | while read -r f; do
    [ "$f" = "$NAME" ] && sync_state
done
