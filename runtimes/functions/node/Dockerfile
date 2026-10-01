# Kiln function runtime: Node.js 24 (TypeScript by type stripping, Hono or any fetch handler). Build context:
# runtimes/functions. See README.md for the kiln-fn-install / kiln-fn-serve / kiln-fn-run convention.
FROM node:24.21.0-slim

COPY shared/ /opt/kiln-fn/shared/
COPY node/install.mjs node/serve.mjs /opt/kiln-fn/node/
COPY --chmod=0755 node/bin/kiln-fn-install node/bin/kiln-fn-serve node/bin/kiln-fn-run /usr/local/bin/

# The root filesystem is read-only at run time: temp files, HOME and npm's logs live under the /tmp tmpfs.
ENV NODE_ENV=production \
    TMPDIR=/tmp \
    HOME=/tmp \
    npm_config_update_notifier=false \
    PORT=8080

WORKDIR /app
USER 65534:65534
EXPOSE 8080
CMD ["kiln-fn-serve"]
