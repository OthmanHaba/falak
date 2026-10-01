# Kiln function runtime: Bun (+ Hono by import). See README.md for the kiln-fn-install / kiln-fn-serve / kiln-fn-run
# convention. Build context: runtimes/functions (the JS runtimes share shared/: tracer, entry loader, scheduled runs).
FROM oven/bun:1.4.2-slim

COPY shared/ /opt/kiln-fn/shared/
COPY bun/install.ts bun/serve.ts /opt/kiln-fn/bun/
COPY --chmod=0755 bun/bin/kiln-fn-install bun/bin/kiln-fn-serve bun/bin/kiln-fn-run /usr/local/bin/

# The root filesystem is read-only at run time: no transpiler cache, temp files and HOME under the /tmp tmpfs.
ENV NODE_ENV=production \
    BUN_RUNTIME_TRANSPILER_CACHE_PATH=0 \
    TMPDIR=/tmp \
    HOME=/tmp \
    PORT=8080

WORKDIR /app
USER 65534:65534
EXPOSE 8080
CMD ["kiln-fn-serve"]
