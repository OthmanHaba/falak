# Falak function runtime: Node.js 24 (TypeScript by type stripping, Hono or any fetch handler). Build context:
# runtimes/functions. See README.md for the falak-fn-install / falak-fn-serve / falak-fn-run convention.
FROM node:24.21.0-slim

COPY shared/ /opt/falak-fn/shared/
COPY node/install.mjs node/serve.mjs /opt/falak-fn/node/
COPY --chmod=0755 node/bin/falak-fn-install node/bin/falak-fn-serve node/bin/falak-fn-run /usr/local/bin/

# The root filesystem is read-only at run time: temp files, HOME and npm's logs live under the /tmp tmpfs.
ENV NODE_ENV=production \
    TMPDIR=/tmp \
    HOME=/tmp \
    npm_config_update_notifier=false \
    PORT=8080

WORKDIR /app
USER 65534:65534
EXPOSE 8080
CMD ["falak-fn-serve"]
