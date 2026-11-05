#!/bin/sh
set -eu

mkdir -p /data/session /data/keys
chown -R node:node /data
chmod 0700 /data/session /data/keys

exec gosu node "$@"
