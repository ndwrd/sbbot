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
git clone https://github.com/ndwrd/sbbot.git
cd ./sbbot
git checkout $TAG
echo "<?php

\$c = ['key' => '$1'];" > ./app/config.php
make u
