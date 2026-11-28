-- Migration 004: refresh-token reuse detection and "return to this page after login" (audit findings 1, 18-19).
-- oauth_tokens keeps ONE row per connection (a "token family"): refresh rotation replaces the hashes in the same row.
-- prev_refresh_hash remembers the refresh token that was just replaced, so presenting it again (a stolen copy, or a
-- client that lost the response) is detected; refresh_rotated_at lets the code tolerate an honest retry shortly after rotation.
ALTER TABLE oauth_tokens ADD COLUMN prev_refresh_hash TEXT;
ALTER TABLE oauth_tokens ADD COLUMN refresh_rotated_at TEXT;
CREATE INDEX idx_oauth_tokens_prev_refresh ON oauth_tokens (prev_refresh_hash);

-- next_path: the page a login link returns to (only a validated plan page; see Auth\LoginLinks). NULL = the home page.
ALTER TABLE login_links ADD COLUMN next_path TEXT;
