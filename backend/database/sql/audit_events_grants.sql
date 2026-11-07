-- Audit trail grants (applied outside migrations, per environment).
--
-- The application user must not be able to UPDATE or DELETE `audit_events`:
-- the append-only guarantee is enforced in three layers (model, DB triggers
-- and these grants). The retention command uses a dedicated connection/user
-- that can DELETE only while it opts in with `SET @audit_retention = 1`.
--
-- Replace the placeholders before running:
--   @app_user       application database user (SELECT/INSERT only)
--   @retention_user retention/archive database user (SELECT/DELETE only)
--   @database       database name
--
-- The retention user does not need UPDATE: retention only archives and deletes
-- the hot rows. `audit_chain_state` is application-owned and is not granted to
-- the retention user.

GRANT SELECT, INSERT ON `@database`.`audit_events` TO '@app_user'@'%';

GRANT SELECT, DELETE ON `@database`.`audit_events` TO '@retention_user'@'%';

-- Explicitly deny the destructive privileges the app must never hold. MySQL
-- has no DENY statement: ensure the app user is not a member of a broader role
-- or does not rely on a global grant. Review with:
--   SHOW GRANTS FOR '@app_user'@'%';
FLUSH PRIVILEGES;
