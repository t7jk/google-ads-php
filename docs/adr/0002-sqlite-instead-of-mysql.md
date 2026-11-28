# 2. SQLite instead of MySQL

Status: accepted (2026-11)

## Context
One administrator and one Google account per installation; low write volume. The target hosting offers MySQL, but a separate
database needs credentials, a server and extra setup for every installation.

## Decision
Use SQLite (one file in the data directory, WAL mode, `busy_timeout`). Keep the SQL portable: standard types, ISO 8601 UTC dates
as TEXT, prepared statements only, no SQLite-specific features except `PRAGMA` and the backup tool (`VACUUM INTO`).

## Consequences
- Zero-configuration install and trivial backups (one file).
- Concurrent writers are serialized; acceptable at this scale.
- Moving to MySQL/PostgreSQL later means changing `Lib/Db` and the schema bootstrap, not the queries.

## Alternatives considered
MySQL on the host: more moving parts for no benefit at this scale.
