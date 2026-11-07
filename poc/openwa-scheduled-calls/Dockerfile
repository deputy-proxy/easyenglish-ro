FROM node:22.21.1-bookworm-slim

ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=8080 \
    OPENWA_CHROME_EXECUTABLE=/usr/bin/chromium \
    OPENWA_SESSION_DIR=/data/session \
    OPENWA_SESSION_KEY_DIR=/data/keys \
    OPENWA_USER_DATA_DIR=/data/profile

RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    chromium-sandbox \
    ca-certificates \
    fonts-liberation \
    gosu \
    zstd \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json package-lock.json ./
COPY scripts/patch-openwa-session-recovery.js ./scripts/patch-openwa-session-recovery.js
RUN npm ci --omit=dev && npm cache clean --force
COPY --chown=node:node . .
COPY docker-entrypoint.sh /usr/local/bin/openwa-entrypoint
RUN chmod 0755 /usr/local/bin/openwa-entrypoint

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/openwa-entrypoint"]
CMD ["node", "src/server.js"]
