path=`pwd`
sed "s|path|$path|g" "$path/scripts/sbbot.service" > /etc/systemd/system/sbbot.service
systemctl daemon-reload
systemctl enable sbbot
