# 6. Path to multiple tenants (not implemented)

Status: accepted as a plan; nothing below is built yet (YAGNI)

## Context
Today one installation = one administrator = one Google account = one Google Ads customer. Global singletons reflect that:
`Settings`, `Google\Token`, `config()` (one `ads_customer_id`), one SQLite file. Stage 2 added what is cheap and useful now:
an owner column `client_id` on `plans` and `uploads`, a scope (`ads.read` / `ads.write`) per MCP client, and per-client visibility
of plans, uploads and events. That separates MCP CLIENTS inside one account; it is not tenant isolation.

## Decision (when a second tenant is really needed)
- One SQLite file and one configuration per tenant, e.g. `google-ads-mcp-php-data/tenants/<tenant>/{app.sqlite,config.php,uploads/,logs/}`.
  A tenant's data cannot leak through a missing `WHERE tenant_id = ?` because there is no shared table to query wrongly.
  (Pattern of MemContinue: a tenant is activated once per request.)
- A single place sets the context: a `Tenant::activate($id)` called by the front controller after resolving the tenant (sub-domain,
  path prefix or the MCP client's record in a small registry database). `Db`, `Settings`, `Google\Token`, `config()` and `DATA_PATH`
  read the active tenant from there and nowhere else; no code takes a tenant id as a parameter.
- Migrations run per tenant database (the `Lib\Migrator` already takes a PDO and a directory), with the same pre-migration backup.
- Per-tenant limits (rate limits, quotas, plan expiry) live in the tenant's config and are enforced server-side.
- Link tokens carry the tenant (as the `context` of `LinkToken`), so a link cannot cross tenants; `link_key_*` may be per tenant.

## Isolation test (write it together with the implementation)
Create tenants A and B, each with a client, a plan, an upload and an event. As A: list, show, approve, undo and read B's items by
id and by guessed id; every attempt must answer "not found" and change nothing. Repeat with tokens and links of B against A's URLs.
The test fails the build if any shared table or file path is reachable without the tenant context.

## Consequences
- Until then: do not add features that assume a single tenant in new places; reach settings and data only through `Db`, `Settings`, `config()`.
- Rows created before stage 2 have no `client_id` and stay visible to every client; they are not tenant data.
- Moving to MySQL/PostgreSQL with a `tenant_id` column instead of a file per tenant remains possible, but needs a mandatory filter in one repository layer; not chosen because it makes a forgotten filter a data leak.

## Alternatives considered
- `tenant_id` column in every table of one database: simple to start, but isolation depends on every query being right.
- One deployment per tenant (what is possible today): the safest isolation, at the cost of a deploy per tenant.
