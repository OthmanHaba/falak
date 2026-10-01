# Kiln function runtime: Python (ASGI apps: FastAPI, Starlette, …). See README.md for the kiln-fn-install /
# kiln-fn-serve / kiln-fn-run convention. Build context: runtimes/functions (this file: python/Dockerfile).
FROM ghcr.io/astral-sh/uv:0.12.21 AS uv

FROM python:3.13.15-slim

COPY --from=uv /uv /usr/local/bin/uv

# The ASGI server every function is served with. A function's own dependencies go to /app/.venv, which is created
# with --system-site-packages and so sees these.
RUN pip install --no-cache-dir --disable-pip-version-check --root-user-action=ignore \
        uvicorn==0.54.0 httptools==0.8.0 websockets==17.1

COPY python/kiln_fn /opt/kiln-fn/kiln_fn
COPY --chmod=0755 python/bin/kiln-fn-install python/bin/kiln-fn-serve python/bin/kiln-fn-run /usr/local/bin/

# The root filesystem is read-only at run time: nothing is written outside /tmp (HOME) and, during install, /app
# and /cache.
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONPATH=/opt/kiln-fn \
    HOME=/tmp \
    TMPDIR=/tmp \
    PORT=8080 \
    KILN_ENTRYPOINT=main.py \
    UV_PYTHON_DOWNLOADS=never \
    UV_LINK_MODE=copy \
    UV_CACHE_DIR=/cache/uv

WORKDIR /app
USER 65534:65534
EXPOSE 8080
CMD ["kiln-fn-serve"]
