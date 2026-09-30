# Kiln function runtime: Bun (+ Hono by import). See README.md for the kiln-fn-install / kiln-fn-serve convention.
FROM oven/bun:1.4.2-slim

COPY install.ts serve.ts /opt/kiln-fn/
COPY --chmod=0755 bin/kiln-fn-install bin/kiln-fn-serve /usr/local/bin/

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
