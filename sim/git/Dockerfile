FROM alpine:3.22
RUN apk add --no-cache git git-daemon rsync
COPY seed.sh /usr/local/bin/seed.sh
RUN chmod +x /usr/local/bin/seed.sh
EXPOSE 9418
HEALTHCHECK --interval=5s --timeout=3s --retries=20 CMD test -f /srv/git/.seeded
ENTRYPOINT ["/usr/local/bin/seed.sh"]
