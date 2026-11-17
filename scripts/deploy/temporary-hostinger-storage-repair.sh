#!/bin/sh
set -eu

base="/home/u583712040/domains/easyenglish.ro"
app="$base/easyenglish-app"
public="$base/public_html/public"
target="../storage/app/public"

[ -d "$base/public_html" ]
[ -d "$public" ]
[ -d "$base/public_html/storage/app/public" ]

if [ -e "$app" ] || [ -L "$app" ]; then
    rm -rf -- "$app"
fi

if [ -L "$public/storage" ]; then
    current="$(readlink "$public/storage")"
    if [ "$current" != "$target" ]; then
        rm -f -- "$public/storage"
        ln -s "$target" "$public/storage"
    fi
elif [ -e "$public/storage" ]; then
    echo "Refusing to replace non-symlink public/storage" >&2
    exit 1
else
    ln -s "$target" "$public/storage"
fi

[ -L "$public/storage" ]
[ -d "$public/storage" ]
echo "OK: obsolete directory removed; public/storage symlink verified"
