#!/usr/bin/env bash
# Compares the vendored report.schema.json against the server repo's version.
# Exit 0 if identical, exit 1 if stale.
#
# Usage: bash contracts/check-schema-staleness.sh [server-contracts-dir]
# Default server dir: ~/dev/404-solution-server/contracts

set -euo pipefail

SERVER_DIR="${1:-$HOME/dev/404-solution-server/contracts}"
LOCAL_SCHEMA="$(dirname "$0")/schemas/report.schema.json"
SERVER_SCHEMA="$SERVER_DIR/schemas/report.schema.json"

if [ ! -f "$LOCAL_SCHEMA" ]; then
  echo "FAIL: vendored schema not found: $LOCAL_SCHEMA"
  exit 1
fi

if [ ! -f "$SERVER_SCHEMA" ]; then
  echo "WARN: server schema not found at $SERVER_SCHEMA (server repo may not be checked out)"
  echo "Skipping staleness check."
  exit 0
fi

# allow-silent-catch: diff exit code 1 means files differ, which is the condition we test for
if diff -q "$LOCAL_SCHEMA" "$SERVER_SCHEMA" > /dev/null 2>&1; then
  echo "OK: vendored schema matches server"
  exit 0
else
  echo "FAIL: vendored schema is stale. Diff:"
  diff -u "$LOCAL_SCHEMA" "$SERVER_SCHEMA" || true
  echo ""
  echo "Copy the updated schema:"
  echo "  cp $SERVER_SCHEMA $LOCAL_SCHEMA"
  exit 1
fi
