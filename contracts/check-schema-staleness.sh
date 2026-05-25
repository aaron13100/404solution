#!/usr/bin/env bash
# Compares the vendored report.schema.json against the server repo's version.
# Exit 0 if identical, exit 1 if stale.
#
# Usage: bash contracts/check-schema-staleness.sh [server-contracts-dir]
# Default server dir: $ABJ404_SERVER_CONTRACTS_DIR, or
#                     ~/dev/404-solution-server/contracts

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
SERVER_DIR="${1:-${ABJ404_SERVER_CONTRACTS_DIR:-$HOME/dev/404-solution-server/contracts}}"
LOCAL_SCHEMA="$SCRIPT_DIR/schemas/report.schema.json"
SERVER_SCHEMA="${SERVER_DIR%/}/schemas/report.schema.json"

if [ ! -f "$LOCAL_SCHEMA" ]; then
  echo "FAIL: vendored schema not found: $LOCAL_SCHEMA" >&2
  exit 1
fi

if [ ! -f "$SERVER_SCHEMA" ]; then
  echo "FAIL: server schema not found: $SERVER_SCHEMA" >&2
  echo "Pass the server contracts directory or set ABJ404_SERVER_CONTRACTS_DIR." >&2
  exit 1
fi

# allow-silent-catch: cmp exit code 1 means files differ, which is the condition we test for
if cmp -s "$LOCAL_SCHEMA" "$SERVER_SCHEMA"; then
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
