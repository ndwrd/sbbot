path=`pwd`
sed "s|path|$path|g" "$path/scripts/watch-port80.service" > /etc/systemd/system/watch-port80.service
systemctl daemon-reload
systemctl enable --now watch-port80
