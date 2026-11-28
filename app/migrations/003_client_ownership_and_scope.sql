-- Migration 003: ownership of plans and uploads per MCP client, and a scope per client (audit findings 10-11).
-- client_id is NULL for rows created before this migration; those stay visible to every client (backward compatible).
-- No foreign key on plans/uploads: a plan is history and must survive deleting its client (SET NULL would make it
-- visible to everybody; CASCADE would erase the audit trail). After a client is deleted its rows are visible to the admin only.
-- scope: 'ads.write' = full access (the behaviour before this migration, so every existing client keeps it);
-- 'ads.read' = only tools annotated readOnlyHint (Lib\McpServer).

ALTER TABLE mcp_clients ADD COLUMN scope TEXT NOT NULL DEFAULT 'ads.write' CHECK (scope IN ('ads.read', 'ads.write'));

ALTER TABLE plans ADD COLUMN client_id TEXT;
CREATE INDEX idx_plans_client ON plans (client_id);

ALTER TABLE uploads ADD COLUMN client_id TEXT;
CREATE INDEX idx_uploads_client ON uploads (client_id, created_at);
