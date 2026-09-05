ARG image
# build stage: собираем sing-box с нужными тегами
FROM golang:1.24-alpine AS build
ARG SING_BOX_VERSION=v1.14.0
ARG TAGS="with_quic with_utls with_acme with_clash_api with_wireguard with_reality_server with_v2ray_api"
RUN apk add --no-cache git ca-certificates \
    && git clone --depth 1 --branch ${SING_BOX_VERSION} https://github.com/SagerNet/sing-box /sing-box \
    && cd /sing-box \
    && CGO_ENABLED=0 go build -trimpath \
        -tags "${TAGS}" \
        -ldflags "-s -w -X 'github.com/sagernet/sing-box/constant.Version=${SING_BOX_VERSION}'" \
        -o /out/sing-box ./cmd/sing-box \
    && /out/sing-box version

# runtime stage: как у xray-контейнера — sshd для управления из PHP
FROM alpine:3.20
RUN apk add --no-cache openssh openssl jq curl ca-certificates tzdata \
    && mkdir -p /root/.ssh /var/run/sshd \
    && chmod 700 /root/.ssh
COPY --from=build /out/sing-box /usr/bin/sing-box
ENV ENV="/root/.ashrc"
