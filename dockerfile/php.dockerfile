ARG image
FROM $image
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
    libqrencode \
    openssh \
    openssl \
    curl \
    git \
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