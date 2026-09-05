-- ===========================================================================
-- My Books — PostgreSQL bootstrap
--
-- Two roles, on purpose.
--
--   my_books      owns the schema. Runs migrations and maintenance.
--                 A table owner BYPASSES row-level security in PostgreSQL,
--                 which is exactly what a migration needs and exactly what
--                 application traffic must never have.
--
--   my_books_app  the role the application connects as. Owns nothing, so
--                 every RLS policy applies to it without exception. If a
--                 query forgets its organisation filter, RLS returns zero
--                 rows instead of another company's ledger.
--
-- This is the second of the two tenant-isolation layers described in
-- docs/adr/0002-multi-tenancy.md. The first is the application's global
-- Eloquent scope. Neither is trusted alone.
-- ===========================================================================

-- The runtime role. NOBYPASSRLS is the default but stated explicitly so the
-- intent survives a future edit.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'my_books_app') THEN
        CREATE ROLE my_books_app LOGIN PASSWORD 'my_books_app' NOBYPASSRLS;
    END IF;
END
$$;

COMMENT ON ROLE my_books_app IS
    'Application runtime role. Owns no objects so RLS is always enforced.';

GRANT CONNECT ON DATABASE my_books TO my_books_app;
GRANT USAGE  ON SCHEMA public      TO my_books_app;

-- Rights on objects that already exist...
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO my_books_app;
GRANT USAGE, SELECT                  ON ALL SEQUENCES IN SCHEMA public TO my_books_app;

-- ...and, more importantly, on every table a future migration creates.
-- Without this, each new migration would silently break the runtime role.
ALTER DEFAULT PRIVILEGES FOR ROLE my_books IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO my_books_app;
ALTER DEFAULT PRIVILEGES FOR ROLE my_books IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO my_books_app;

-- The runtime role must never create objects.
REVOKE CREATE ON SCHEMA public FROM my_books_app;
