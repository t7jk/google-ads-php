# 3. Deployment over FTP only

Status: accepted (2026-11)

## Context
The hosting plan gives FTP(S) access only: no SSH, no Git, no build step, no background processes.

## Decision
Deploy with `tools/deploy.sh` (lftp over FTPS, certificate verified). The script checks syntax and tests locally, uploads the new
`app/` next to the old one, swaps the directories and keeps the previous release as `app.prev` for rollback.

## Consequences
- Repeatable deployment and a quick rollback without server-side tooling.
- No server-side migrations or hooks: anything the app must do on a new version happens lazily on a request.
- FTP has no server-side copy, so the swap is two renames, not an atomic operation.

## Alternatives considered
Manual upload (error-prone, no rollback); a Git-based deploy (not available on this hosting).
