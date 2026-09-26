#!/bin/sh
# Seeds one bare repo per fixture app (branch main) and serves them read-only over git://.
# Repos: git://sim-git/<app>.git   Re-seeding on every start keeps them in sync with sim/fixtures.
set -eu

apps=/fixtures/apps
repos=/srv/git
rm -rf "$repos" && mkdir -p "$repos"
git config --global user.name "Kiln Sim"
git config --global user.email "sim@kiln.test"
git config --global init.defaultBranch main

for app in "$apps"/*/; do
    name=$(basename "$app")
    work=$(mktemp -d)
    rsync -a --exclude vendor --exclude node_modules --exclude .env "$app" "$work/"

    # The Laravel demo vendors the in-repo APM package through a composer path repository.
    if [ -f "$work/composer.json" ] && grep -q '"kiln/apm-laravel"' "$work/composer.json"; then
        mkdir -p "$work/packages"
        rsync -a --exclude vendor --exclude composer.lock /packages/apm-laravel/ "$work/packages/kiln-apm-laravel/"
    fi

    git -C "$work" init -q
    git -C "$work" add -A
    [ -d "$work/packages/kiln-apm-laravel" ] && git -C "$work" add -f packages/kiln-apm-laravel
    git -C "$work" commit -q -m "Initial commit of $name"
    git clone -q --bare "$work" "$repos/$name.git"
    touch "$repos/$name.git/git-daemon-export-ok"
    rm -rf "$work"
    echo "seeded git://sim-git/$name.git"
done

touch "$repos/.seeded"
exec git daemon --reuseaddr --base-path="$repos" --export-all --verbose "$repos"
