if [[ ! -s "/ssh/key" ]]; then
    rm -f /ssh/key*
    ssh-keygen -m PEM -t rsa -f /ssh/key -N ''
fi
openssl req -newkey rsa:2048 -sha256 -nodes -x509 -days 365 -keyout /certs/self_private -out /certs/self_public  -subj "/C=NN/ST=N/L=N/O=N/CN=$IP"
# Декой-заглушка (location / в nginx) ссылается на auth_basic_user_file
# /app/.htpasswd, но файл никогда нигде не создавался — и nginx на каждый заход
# отдавал 500 вместо 401 ("open() /app/.htpasswd failed" в nginx_error). То есть
# страница, которая должна выглядеть обычным сайтом под паролем, выдавала ошибку
# сервера — ровно тот признак, по которому её и отличают от настоящего сайта.
#
# Путь тут /app/webapp, а не /app: в контейнере ng /app примонтирован из
# ./app/webapp (docker-compose), а в php-контейнере /app — это ./app целиком.
#
# Логин и пароль случайные и нигде не сохраняются: заходить по ним не нужно и
# некому, нужен только сам факт корректного 401-ответа. -s, а не -f — пустой
# файл (оборванная генерация) тоже перегенерируем.
if [[ ! -s "/app/webapp/.htpasswd" ]]; then
    printf '%s:%s\n' \
        "$(head -c 6 /dev/urandom | xxd -ps)" \
        "$(openssl passwd -apr1 "$(head -c 18 /dev/urandom | xxd -ps)")" \
        > /app/webapp/.htpasswd
fi
if [[ -f "/ssh/key.pub" && -s "/ssh/key.pub" ]]; then
    unitd --control 0.0.0.0:8080 --log /logs/unit_error
    curl -X PUT --data-binary @/config/unit.json http://127.0.0.1:8080/config
    php init.php
else
    exit 1;
fi
