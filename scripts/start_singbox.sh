cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
exec /usr/sbin/sshd -D -e "$@" &
uuid=$(cat /sing-box/config.json | jq -r '.inbounds[0].users[0].uuid // empty' 2>/dev/null)
if [ -n "$uuid" ]; then
    sing-box run -c /sing-box/config.json > /dev/null 2>&1 &
fi
tail -f /dev/null
