ARG image
FROM $image
# Пакет phpXX кладёт только /usr/bin/phpXX. Симлинк /usr/bin/php даёт лишь одна
# версия в каждом релизе Alpine — "дефолтная" (в 3.18 это был php81, поэтому
# раньше всё работало само; в 3.22 это php83). Без симлинка ниже контейнер
# падает с кодом 127 на `php init.php` в start_php.sh — а голый php зовётся ещё
# и из start_service.sh, makefile и nodeConsole() на нодах.
# Проверка, что всё на месте, — отдельным RUN в конце файла.
RUN apk add --no-cache --update php84 \
    php84-mbstring \
    php84-session \
    php84-phar \
    php84-zip \
    php84-curl \
    php84-opcache \
    php84-openssl \
    php84-iconv \
    php84-intl \
    php84-pecl-ssh2 \
    php84-pecl-yaml \
    unit \
    unit-php84 \
    xxd \
    certbot \
    libqrencode-tools \
    openssh \
    openssl \
    curl \
    git \
    && ln -sf /usr/bin/php84 /usr/bin/php \
    && mkdir /root/.ssh \
    && wget https://github.com/ameshkov/dnslookup/releases/download/v1.11.1/dnslookup-linux-amd64-v1.11.1.tar.gz \
    && tar -xf dnslookup-linux-amd64-v1.11.1.tar.gz \
    && mv linux-amd64/dnslookup /usr/bin \
    && rm dnslookup-linux-amd64-v1.11.1.tar.gz \
    && rm -rf /linux-amd64 \
    && wget https://github.com/SagerNet/sing-box/releases/download/v1.14.0/sing-box-1.14.0-linux-amd64-musl.tar.gz \
    && tar -xf sing-box-1.14.0-linux-amd64-musl.tar.gz \
    && mv sing-box-1.14.0-linux-amd64-musl/sing-box /usr/bin \
    && rm sing-box-1.14.0-linux-amd64-musl.tar.gz \
    && rm -rf /sing-box-1.14.0-linux-amd64-musl \
    && wget https://github.com/MetaCubeX/mihomo/releases/download/v1.18.10/mihomo-linux-amd64-v1.18.10.gz \
    && gunzip mihomo-linux-amd64-v1.18.10.gz \
    && mv mihomo-linux-amd64-v1.18.10 /usr/bin/mihomo

# Проверка на сборке, а не на сервере. Оба промаха при переезде на 3.22 были
# именно такими: php остался без симлинка /usr/bin/php (контейнер падал с 127),
# а бинарник qrencode переехал из libqrencode в libqrencode-tools (QR-коды
# молча перестали бы генерироваться). Оба видно отсюда сразу.
RUN set -e; \
    for b in php unitd qrencode certbot xxd openssl curl git ssh-keygen dnslookup sing-box mihomo; do \
        command -v "$b" >/dev/null || { echo "ОТСУТСТВУЕТ БИНАРНИК: $b"; exit 1; }; \
    done; \
    for e in ssh2 yaml intl mbstring curl openssl session iconv phar zip Zend\ OPcache; do \
        php -m | grep -qix "$e" || { echo "ОТСУТСТВУЕТ РАСШИРЕНИЕ PHP: $e"; exit 1; }; \
    done; \
    php -v; \
    echo "проверка образа пройдена"