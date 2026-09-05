-include .env
export

IP := $(or $(IP),$(shell hostname -I | awk '{print $$1}'))

b:
	docker compose build
preup:
	bash ./update/update.sh &
start: # запуск контейнеров
	touch ./override.env ./docker-compose.override.yml ./config/location.conf ./config/override.conf
	IP=$(IP) VER=$(shell git describe --tags) docker compose --env-file ./.env --env-file ./override.env up -d --force-recreate
u: preup start
d: # остановка контейнеров
	-kill -9 $(shell cat ./update/update_pid) > /dev/null
	docker compose down --remove-orphans
dv: # остановка контейнеров
	docker compose down -v
r: d u
ps: # список контейнеров
	docker compose ps
l: # логи из контейнеров
	docker compose logs
php: # консоль сервиса
	docker compose exec php /bin/sh
ng: # консоль сервиса
	docker compose exec ng /bin/sh
up: # консоль сервиса
	docker compose exec up /bin/sh
ad: # консоль сервиса
	docker compose exec ad /bin/sh
wp: # консоль сервиса
	docker compose exec wp /bin/sh
tg: # консоль сервиса
	docker compose exec tg /bin/bash
dnstt: # консоль сервиса
	docker compose exec dnstt /bin/sh
sbx: # консоль сервиса
	docker compose exec sbx /bin/sh
service: # консоль сервиса
	docker compose exec service /bin/sh
delete:
	make d
	docker system prune -f -a
	docker volume prune -f -a
	rm -rf /root/sbbot
push:
	docker compose push
s:
	git status -su
c:
	git add config/
	git checkout .
	git reset
webhook:
	docker compose exec php php checkwebhook.php
reset:
	make d
	git reset --hard
	git clean -fd
	docker volume rm sbbot_adguard sbbot_warp
	make u
backup:
	docker compose exec php php backup.php > backup.json
mtproto:
	docker compose exec php php mtproto.php
cron: # установка задачи в cron для автозапуска при перезагрузке
	@(crontab -l 2>/dev/null | grep -v "cd /root/sbbot && make r"; echo "@reboot cd /root/sbbot && make r") | crontab -
uncron: # удаление задачи из cron
	@crontab -l 2>/dev/null | grep -v "cd /root/sbbot && make r" | crontab -