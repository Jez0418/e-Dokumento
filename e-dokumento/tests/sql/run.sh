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

for f in sql/01_schema.sql sql/02_functions.sql sql/03_policies.sql sql/04_storage.sql sql/05_seed.sql sql/09_email_outbox.sql sql/10_captain_signature.sql sql/11_close_account_on_exit.sql sql/12_officials_captain_only.sql sql/13_one_captain_account.sql sql/14_resident_id.sql sql/15_sync_resident_account.sql sql/16_notify_admin_on_role.sql sql/17_lock_down_function_grants.sql sql/18_one_account_per_staff_role.sql tests/sql/fixtures.sql; do
  echo "Applying $f"
  docker exec -i "$container" psql -U postgres -d postgres -v ON_ERROR_STOP=1 -q < "$f"
done

npx --yes supabase test db --local
