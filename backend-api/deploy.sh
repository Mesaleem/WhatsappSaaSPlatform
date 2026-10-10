#!/bin/bash
# Production deploy script for sahilmoney.in/WebHubs (backend-api).
#
# Run from the webroot (~/public_html/WebHubs), which mirrors backend-api/'s
# own contents directly -- `git archive origin/master:backend-api` extracts
# this repo's backend-api/ subtree flat into the current directory, so this
# file itself re-deploys on every run too (it lives at backend-api/deploy.sh).
#
# [Fixed 2026-10-10, disclosed]: this used to back up bootstrap/app.php and
# public/index.php before the archive step and restore those backups
# afterwards, on every single deploy. That meant neither file was EVER
# actually updated past whatever version was first backed up -- any new
# middleware alias (or anything else) added to bootstrap/app.php in git
# silently never reached production, however many times this ran. That
# caused routes using a newer alias (target.account, capability.guard, ...)
# to 500 with "Target class [...] does not exist", surfacing as a generic
# Server Error under APP_DEBUG=false. git archive only ever includes
# TRACKED files (respecting .gitignore), so .env/storage/bootstrap/cache
# were never at risk from the archive step either way -- there was nothing
# production-specific for the backup/restore to actually protect. Removed;
# both files now deploy from git like everything else.
set -e
cd ~/public_html/WebHubs

git fetch origin master
git archive origin/master:backend-api | tar -x -C .

php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan optimize
php artisan queue:restart
