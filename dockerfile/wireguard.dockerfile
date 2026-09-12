ARG image
FROM $image
RUN apk add --no-cache --virtual .build-deps alpine-sdk git \
    && apk add iproute2 linux-headers iptables xtables-addons openssh wireguard-tools jq bash htop \
    && git clone https://github.com/rofl0r/microsocks \
    && cd /microsocks \
    && make && mv microsocks /usr/bin/microsocks \
    && apk del .build-deps \
    && rm -rf /microsocks \
    && mkdir /root/.ssh \
    && wget -O /usr/bin/wgcf https://github.com/ViRb3/wgcf/releases/download/v2.2.22/wgcf_2.2.22_linux_amd64 \
    && chmod +x /usr/bin/wgcf \
    && sed -i 's/sysctl -q net\.ipv4\.conf\.all\.src_valid_mark=1/echo "skip sysctl src_valid_mark"/' $(which wg-quick)
