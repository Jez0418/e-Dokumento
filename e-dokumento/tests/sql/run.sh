#!/usr/bin/env bash
# Database tests. Runs ONLY against the local Supabase stack in Docker
# (container supabase_db_e-dokumento), never against the live project.
# Takes no database URL on purpose.
set -euo pipefail
cd "$(dirname "$0")/../.."

container=supabase_db_e-dokumento

npx --yes supabase start

if ! docker ps --format '{{.Names}}' | grep -qx "$container"; then
  echo "Local Supabase container $container is not running" >&2
  exit 1
fi

npx --yes supabase db reset --local

for f in sql/01_schema.sql sql/02_functions.sql sql/03_policies.sql sql/04_storage.sql sql/05_seed.sql tests/sql/fixtures.sql; do
  echo "Applying $f"
  docker exec -i "$container" psql -U postgres -d postgres -v ON_ERROR_STOP=1 -q < "$f"
done

npx --yes supabase test db --local
