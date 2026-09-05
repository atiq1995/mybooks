-- ===========================================================================
-- Test database.
--
-- Tests run against real PostgreSQL, never SQLite: constraints, triggers and
-- row-level security ARE the logic under test, and a test that mocks them
-- away proves nothing about production.
--
-- Same two-role arrangement as the main database, so an RLS test can
-- `SET ROLE my_books_app` and observe exactly what production would.
-- ===========================================================================
CREATE DATABASE my_books_test OWNER my_books;

GRANT CONNECT ON DATABASE my_books_test TO my_books_app;

\connect my_books_test

GRANT USAGE ON SCHEMA public TO my_books_app;
REVOKE CREATE ON SCHEMA public FROM my_books_app;

ALTER DEFAULT PRIVILEGES FOR ROLE my_books IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO my_books_app;
ALTER DEFAULT PRIVILEGES FOR ROLE my_books IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO my_books_app;
