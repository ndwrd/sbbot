TAG="${2:-main}"
apt update
apt install -y \
    ca-certificates \
    curl \
    gnupg \
    lsb-release \
    make \
    git \
    iptables \
    iproute2 \
    xtables-addons-common \
    xtables-addons-dkms \
    inotify-tools
curl -fsSL https://get.docker.com -o get-docker.sh && sh get-docker.sh

# Best-effort IPv6 egress for containers (lets sing-box's direct outbound reach
# IPv6-only destinations) — skipped if the kernel lacks NAT66 support or if
# daemon.json already has custom content, so this never breaks docker startup
# or clobbers an existing config; run manually later if it's skipped here.
if [ ! -f /etc/docker/daemon.json ] && (modprobe -q ip6table_nat 2>/dev/null || lsmod | grep -q ip6table_nat); then
    mkdir -p /etc/docker
    cat > /etc/docker/daemon.json <<'JSON'
{
  "ipv6": true,
  "fixed-cidr-v6": "fd00:1::/64",
  "ip6tables": true,
  "experimental": true
}
JSON
    systemctl restart docker
else
    echo "Skipping automatic IPv6 docker setup (daemon.json exists or ip6tables/NAT66 unsupported)" >&2
fi

git clone https://github.com/ndwrd/sbbot.git
cd ./sbbot
git checkout $TAG
echo "<?php

\$c = ['key' => '$1'];" > ./app/config.php
make u
