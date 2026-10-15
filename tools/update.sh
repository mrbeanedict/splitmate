#!/usr/bin/env bash
# Update Setlo on the server: pull the latest code from GitHub, then bring the database up to date.
# Run it over SSH from anywhere:   ~/www/setlo/tools/update.sh
# It never runs database/setup.php (that wipes the data) and never touches uploads/ or config/local.php
# (git ignores both).
set -euo pipefail
cd "$(dirname "$0")/.."

before=$(git rev-parse --short HEAD)
if ! git pull --ff-only; then
  echo
  echo "Could not update: files on the server were changed by hand and clash with GitHub."
  echo "Run 'git status' to see which, undo them with 'git checkout -- <file>', then run this again."
  exit 1
fi
after=$(git rev-parse --short HEAD)

# migrate.php needs MariaDB (it uses ADD COLUMN IF NOT EXISTS). On MySQL 8 it stops with an SQL error;
# that is harmless for a fresh install, see HOSTING-ALWAYSDATA.md.
php database/migrate.php

echo
if [ "$before" = "$after" ]; then
  echo "Already up to date ($after)."
else
  echo "Updated $before -> $after. The site is live with the new version."
fi
