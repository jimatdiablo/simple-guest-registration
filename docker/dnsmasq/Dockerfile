FROM alpine:3.20@sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc

ARG BUILD_DATE=""
ARG VCS_REF=""
ARG VERSION="dev"

LABEL org.opencontainers.image.title="Simple Guest Registration DNS" \
      org.opencontainers.image.description="dnsmasq-based poison DNS companion image for Simple Guest Registration lab and deployment stacks." \
      org.opencontainers.image.source="https://github.com/jimatdiablo/simple-guest-registration" \
      org.opencontainers.image.vendor="Diablo Data" \
      org.opencontainers.image.licenses="LicenseRef-Diablo-Data-Source-Available" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.version="${VERSION}"

RUN apk add --no-cache dnsmasq

COPY docker/dnsmasq/entrypoint.sh /usr/local/bin/sgr-dnsmasq-entrypoint
RUN chmod +x /usr/local/bin/sgr-dnsmasq-entrypoint

EXPOSE 53/udp
EXPOSE 53/tcp

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD pgrep dnsmasq >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/sgr-dnsmasq-entrypoint"]
