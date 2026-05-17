FROM alpine:3.20

RUN apk add --no-cache dnsmasq

COPY docker/dnsmasq/entrypoint.sh /usr/local/bin/sgr-dnsmasq-entrypoint
RUN chmod +x /usr/local/bin/sgr-dnsmasq-entrypoint

EXPOSE 53/udp
EXPOSE 53/tcp

ENTRYPOINT ["/usr/local/bin/sgr-dnsmasq-entrypoint"]
