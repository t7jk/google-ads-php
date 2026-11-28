# 5. Plan approval is a human act outside the model's reach

Status: accepted (2026-11)

## Context
Every change to the Google Ads account goes through a plan: a `prepare_*` tool builds and validates it, a person approves it.
The audit (finding 1) showed that "a person approves" was only a convention:

- the plan page link (`/plan?id=&t=`, HMAC token, 24 h) is returned to the model in tool results, and `POST /plan/approve`
  needed nothing else, so any client with HTTP access (Claude Code) could approve its own plan;
- the MCP tool `approve_plan` ran for every client named `claude-ai*`, with only the plan id and the plan hash (which `show_plan` returned).

Threat model: the model (or anything that can steer it, e.g. text in a web page or a report it reads) can call tools, sees every
tool result including links and hashes, and can make HTTP requests. It cannot read the administrator's mailbox and has no admin session.
Approval must therefore depend on something only the administrator has.

## Decision
1. `approve_plan` is OFF by default. Config `allow_tool_approve` (default `false`) must be `true` for the tool to be listed, callable
   and for the preview window (MCP Apps) to be attached to the tools; the plan hash is withheld from the model otherwise.
2. `POST /plan/approve` requires all of: an active admin session, the plan link token (it doubles as the form's CSRF secret) and the plan hash.
   Without a session nothing is touched (HTTP 403, logged). The link token alone opens the page but changes nothing.
3. The plan page without a session shows a login form (admin e-mail address). The login link carries a validated return path
   (`login_links.next_path`, only `/plan?id=<32 hex>&t=<token>`), so the administrator lands on the plan page, now logged in, and clicks Approve.
   The `approveUrl` given to the model still points to that page.

## Consequences
- The model cannot approve its own plans: it has no admin session, and the login link goes to the administrator's e-mail.
- Approval costs one extra step (log in once per session: 2 h idle, 12 h maximum). `allow_tool_approve => true` brings back one-click approval
  in the preview window, knowingly trading the guarantee away; the option exists for installations where that is acceptable.
- Residual risk: a model that can read the administrator's mailbox (another connector) could follow the login link. Out of scope here.
- `auto_execute` (plans executed without approval) is unchanged and stays `false` on dev.

## Alternatives considered
- One-time nonce issued only to the preview window: protects against replay, not against a model that is the one calling the tool.
- Second factor per plan (e-mail code): stronger, but heavier for every plan; the admin session already proves control of the mailbox.
- Keeping the tool and relying on the client's own confirmation prompt: not under this server's control.
