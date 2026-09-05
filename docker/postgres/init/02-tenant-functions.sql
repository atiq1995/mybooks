-- ===========================================================================
-- Tenant-scope plumbing used by every row-level security policy.
--
-- The application sets `app.organization_id` once per request, inside the
-- transaction, via SetPostgresTenantContext middleware. Policies read it
-- through these functions rather than calling current_setting() directly,
-- so the fallback behaviour is defined in exactly one place.
-- ===========================================================================

-- Current organisation, or NULL when no tenant context has been established.
-- `true` as the second argument makes a missing setting return NULL rather
-- than raising — a request outside tenant context sees nothing, it does not
-- crash with a confusing 500.
CREATE OR REPLACE FUNCTION app_current_organization_id()
RETURNS uuid
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
    SELECT NULLIF(current_setting('app.organization_id', true), '')::uuid;
$$;

COMMENT ON FUNCTION app_current_organization_id() IS
    'Organisation for the current transaction, set by application middleware. NULL means no tenant context, which every RLS policy treats as "no access".';

-- Escape hatch for genuinely cross-tenant work: the migrate job, the
-- verify-ledger command, backups. Set deliberately and never by web traffic.
CREATE OR REPLACE FUNCTION app_is_unscoped()
RETURNS boolean
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
    SELECT coalesce(current_setting('app.unscoped', true), 'off') = 'on';
$$;

COMMENT ON FUNCTION app_is_unscoped() IS
    'True only when a maintenance process has explicitly opted out of tenant scoping. Never set on a web request.';
