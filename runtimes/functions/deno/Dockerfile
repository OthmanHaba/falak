# Falak function runtime: Deno 2 (TypeScript, Hono or any fetch handler, npm: / jsr: packages). Build context:
# runtimes/functions. See README.md for the falak-fn-install / falak-fn-serve / falak-fn-run convention.
FROM denoland/deno:debian-2.9.7

COPY shared/ /opt/falak-fn/shared/
COPY deno/install.mjs deno/serve.mjs /opt/falak-fn/deno/
COPY --chmod=0755 deno/bin/falak-fn-install deno/bin/falak-fn-serve /usr/local/bin/
RUN ln -s falak-fn-serve /usr/local/bin/falak-fn-run

# The root filesystem is read-only at run time: Deno's own cache (DENO_DIR), temp files and HOME live under the
# /tmp tmpfs; modules come from /app (node_modules, vendor) only.
ENV DENO_DIR=/tmp/deno \
    DENO_NO_UPDATE_CHECK=1 \
    DENO_NO_PROMPT=1 \
    NO_COLOR=1 \
    TMPDIR=/tmp \
    HOME=/tmp \
    PORT=8080

WORKDIR /app
USER 65534:65534
EXPOSE 8080
CMD ["falak-fn-serve"]
