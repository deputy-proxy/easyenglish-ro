#!/bin/sh
set -eu

mkdir -p /data/session /data/keys /data/profile
chown -R node:node /data
chmod 0700 /data/session /data/keys /data/profile

# This image is dedicated to the HTTP service. Ignore Railway's legacy custom
# start command so it cannot accidentally run only the preflight and exit.

gosu node node src/preflight.js
exec gosu node node src/server.js
