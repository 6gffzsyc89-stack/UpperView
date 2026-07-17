#!/bin/sh
# One-shot deploy for upperviewsamui.com — run by a temporary Hostinger cron.
# Downloads this repo's site/ folder into public_html, then marks itself done.
H=/home/u357356777
P=$H/domains/upperviewsamui.com/public_html
[ -f "$H/tmp/uv-deployed" ] && exit 0
mkdir -p "$H/tmp" && cd "$H/tmp" || exit 1
rm -rf uv.zip uvdep
curl -sL -o uv.zip https://codeload.github.com/6gffzsyc89-stack/UpperView/zip/refs/heads/main || exit 1
unzip -q uv.zip -d uvdep || exit 1
cp -a uvdep/UpperView-main/site/. "$P"/ || exit 1
rm -f "$P/upper-view-website.dc.html" "$P/support.js" "$P/default.php"
rm -rf uv.zip uvdep
touch "$H/tmp/uv-deployed"
