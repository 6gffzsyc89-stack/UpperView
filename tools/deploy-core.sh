#!/bin/sh
# Deploy the engine to the SHARED CORE (one backend, all sites).
# Webroot booking/ dirs hold only 2-line stubs — never deploy engine
# files there. UV profile + common/ deploy from this repo; the Sonata
# profile deploys from the Sonata repo (same pattern).
set -e
cd "$(dirname "$0")/.."
tar cz -C site/booking $(cd site/booking && ls *.php) | ssh -i ~/.ssh/crownfaith_hostinger -p 65002 u357356777@46.17.175.120 'tar xz -C shared-core/uv'
tar cz -C site/common $(cd site/common && ls) | ssh -i ~/.ssh/crownfaith_hostinger -p 65002 u357356777@46.17.175.120 'tar xz -C shared-core/common && cd shared-core && for f in uv/*.php common/*.php; do php -l "$f" >/dev/null 2>&1 || echo "LINT FAIL $f (web PHP may still accept it)"; done; echo core-deployed'
