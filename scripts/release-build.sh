#!/usr/bin/env bash
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUTDIR="${1:-$ROOT/dist}"
mkdir -p "$OUTDIR"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT
rsync -a \
  --exclude 'dist' \
  --exclude '.git' \
  --exclude 'scripts/*.log' \
  --exclude '__MACOSX' \
  "$ROOT/" "$TMPDIR/fieldflow/"
cd "$TMPDIR"
zip -qr "$OUTDIR/fieldflow-release.zip" fieldflow
printf '[OK] %s\n' "$OUTDIR/fieldflow-release.zip"
