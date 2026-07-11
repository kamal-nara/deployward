#!/usr/bin/env bash
# Builds a distributable plugin zip (deployward-<version>.zip) from the last commit.
# Dev-only files are excluded via export-ignore rules in .gitattributes.
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(sed -n "s/^define('DEPLOYWARD_VERSION', '\([0-9][0-9.]*\)');$/\1/p" deployward.php)
if [ -z "$VERSION" ]; then
  echo "error: could not read DEPLOYWARD_VERSION from deployward.php" >&2
  exit 1
fi

OUT="deployward-${VERSION}.zip"

if [ -n "$(git status --porcelain)" ]; then
  echo "warning: you have uncommitted changes; the zip is built from the last commit (HEAD) and will not include them" >&2
fi

git archive --format=zip --prefix=deployward/ --output="$OUT" HEAD

echo "Built $OUT"
unzip -l "$OUT" | tail -1
