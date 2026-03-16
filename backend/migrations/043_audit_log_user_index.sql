-- CLMS Migration 043: Audit log index for user_id (UMS activity filtering)
-- Rollback: DROP INDEX idx_audit_user ON audit_log;

CREATE INDEX idx_audit_user ON audit_log (user_id);
