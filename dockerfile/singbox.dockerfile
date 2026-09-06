ARG image
# build stage: собираем sing-box с нужными тегами
FROM golang:1.24-alpine AS build
ARG SING_BOX_VERSION=v1.14.0
# "Built in by default" in the docs means "default in sing-box's own Makefile/
# release process" — a plain `go build -tags "..."` like this one gets NOTHING
# unless listed here explicitly. This is the exact list from their own
# release/DEFAULT_BUILD_TAGS_OTHERS (v1.14.0), plus with_v2ray_api for stats.
ARG TAGS="with_gvisor,with_quic,with_dhcp,with_wireguard,with_utls,with_acme,with_clash_api,with_tailscale,with_ccm,with_ocm,with_cloudflared,with_usbip,with_openvpn,with_openconnect,badlinkname,tfogo_checklinkname0,with_v2ray_api"
# sing-box's go.mod can require a newer Go than this base image ships (e.g. v1.14.0
# needs >=1.25.5 while this image has 1.24.x) — auto lets Go fetch that toolchain
# on demand instead of hard-failing with "go.mod requires go >= X".
ENV GOTOOLCHAIN=auto
RUN apk add --no-cache git ca-certificates \
    && mkdir -p /out \
    && git clone --depth 1 --branch ${SING_BOX_VERSION} https://github.com/SagerNet/sing-box /sing-box \
    && cd /sing-box \
    && CGO_ENABLED=0 go build -trimpath \
        -tags "${TAGS}" \
        -ldflags "-s -w -X 'github.com/sagernet/sing-box/constant.Version=${SING_BOX_VERSION}'" \
        -o /out/sing-box ./cmd/sing-box \
    && /out/sing-box version

# runtime stage: как у xray-контейнера — sshd для управления из PHP
FROM alpine:3.20
ARG SING_BOX_VERSION=v1.14.0
# grpcurl — готовый gRPC-клиент (как curl, только для gRPC), нужен только чтобы
# спросить experimental.v2ray_api.StatsService у самого sing-box (тег with_v2ray_api).
# stats.proto тянем с ТОГО ЖЕ тега sing-box, что собран выше — иначе схема может
# разойтись с тем, что реально отдаёт бинарник.
ARG GRPCURL_VERSION=1.9.4
RUN apk add --no-cache openssh openssl jq curl ca-certificates tzdata \
    && mkdir -p /root/.ssh /var/run/sshd /etc/singbox \
    && chmod 700 /root/.ssh \
    && curl -fsSL "https://github.com/fullstorydev/grpcurl/releases/download/v${GRPCURL_VERSION}/grpcurl_${GRPCURL_VERSION}_linux_x86_64.tar.gz" -o /tmp/grpcurl.tar.gz \
    && tar -xzf /tmp/grpcurl.tar.gz -C /usr/bin grpcurl \
    && rm /tmp/grpcurl.tar.gz \
    && curl -fsSL "https://raw.githubusercontent.com/SagerNet/sing-box/${SING_BOX_VERSION}/experimental/v2rayapi/stats.proto" -o /etc/singbox/stats.proto
COPY --from=build /out/sing-box /usr/bin/sing-box
ENV ENV="/root/.ashrc"
