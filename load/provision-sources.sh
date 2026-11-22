#!/usr/bin/env bash
# Creates one tenant `site` source per fixture shop so searches use the farm's plain-HTTP search page
# (the generic search templates for domains without a source are https-only). Safe to re-run: a duplicate
# is reported with the API's error body and skipped.
#
#   API_URL=http://localhost:8080/api/v1 API_KEY=pl_live_... ./load/provision-sources.sh [N_SITES]
set -euo pipefail

API_URL="${API_URL:-http://localhost:8080/api/v1}"
API_KEY="${API_KEY:?set API_KEY to the load tenant API key (pl_live_...)}"
SITES="${1:-10}"
out="$(mktemp)"
trap 'rm -f "${out}"' EXIT

# Two backslashes in the JSON string = one in the regex: ^http://shop-NN\.fixture\.test/p/[\w-]+$
bs="\\\\"

for n in $(seq -f '%02g' 1 "${SITES}"); do
  host="shop-${n}.fixture.test"
  pattern="^http://shop-${n}${bs}.fixture${bs}.test/p/[${bs}w-]+\$"
  body="{\"name\":\"Fixture ${host}\",\"type\":\"site\",\"domain\":\"${host}\",\"country\":\"US\",\"currency\":\"USD\",\"render\":\"never\",\"config\":{\"search_url_template\":\"http://${host}/search?q={query}\",\"product_url_patterns\":[\"${pattern}\"]}}"
  code=$(curl -sS -o "${out}" -w '%{http_code}' -X POST "${API_URL}/sources" \
    -H "Authorization: Bearer ${API_KEY}" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data "${body}")
  echo "${host}: HTTP ${code}"
  if [[ "${code}" != 2* ]]; then
    head -c 400 "${out}"
    echo
  fi
done
