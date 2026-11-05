#!/bin/sh
set -eu

mkdir -p /data/session /data/keys
chown -R node:node /data
chmod 0700 /data/session /data/keys

# Railway's previous custom command ran the preflight as the service command and never
# reached the HTTP server. Normalize that legacy override to the actual server command.
case "$*" in
  *"npm run preflight"*"node src/server.js"*) set -- node src/server.js ;;
esac

gosu node node src/preflight.js
exec gosu node "$@"
