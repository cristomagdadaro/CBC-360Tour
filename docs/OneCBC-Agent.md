# OneCBC Agent Instructions

## Objective
Implement a secure ingestion endpoint in `onecbc.philrice.gov.ph` that accepts guest analytics events from the CBC 360 Tour app and stores them with strong validation, idempotency, and minimal latency.

## Source System
- Source app: `cbc-360-tour`
- Delivery style: server-to-server JSON `POST`
- Authentication: Laravel Sanctum Personal Access Token in the `Authorization: Bearer <token>` header
- Optional integrity header: `X-Signature: sha256=<hmac>` when a shared HMAC secret is configured
- Event header: `X-Guest-Event: guest.visit.recorded`
- Idempotency header: `X-Idempotency-Key: <session_id>`

## Expected Endpoint
- Route: `POST /api/integrations/guest-analytics`
- Middleware:
  - `auth:sanctum`
  - `throttle:guest-analytics`
  - force JSON responses
  - optional custom HMAC verification middleware if `X-Signature` is used
- Token ability:
  - require a narrow Sanctum ability such as `guest-analytics:write`

## Request Contract
Accept JSON like:

```json
{
  "source": "cbc-360-tour",
  "audience": "onecbc",
  "event": "guest.visit.recorded",
  "idempotency_key": "session-id",
  "sent_at_utc": "2026-04-14T10:00:00Z",
  "guest": {
    "visitor_id": "stable-visitor-id",
    "session_id": "unique-session-id",
    "visited_at_utc": "2026-04-14T10:00:00Z",
    "ip_hash": "sha256-hash",
    "user_agent": "browser ua",
    "browser_family": "Chrome",
    "os_family": "Windows",
    "accept_language": "en-US,en;q=0.9",
    "referrer": "https://example.com",
    "landing_path": "/",
    "query_string": "utm_source=x",
    "host": "dacbc.philrice.gov.ph",
    "is_mobile": 0,
    "source": "cbc-360-tour"
  }
}
```

## Validation Rules
- Validate `event` equals `guest.visit.recorded`
- Validate `source` equals `cbc-360-tour` or an explicit allowlist
- Validate `idempotency_key` and `guest.session_id` are required strings and identical
- Validate all timestamps are ISO-8601 UTC strings
- Validate `ip_hash` is a 64-character lowercase hex SHA-256 string
- Validate `landing_path` starts with `/`
- Enforce max lengths on all strings
- Reject unknown top-level payload shapes if practical

## Storage Requirements
- Use a dedicated table, for example `guest_analytics_events`
- Add a unique index on `session_id`
- Recommended columns:
  - `session_id`
  - `visitor_id`
  - `event`
  - `source`
  - `visited_at_utc`
  - `sent_at_utc`
  - `ip_hash`
  - `user_agent`
  - `browser_family`
  - `os_family`
  - `accept_language`
  - `referrer`
  - `landing_path`
  - `query_string`
  - `host`
  - `is_mobile`
  - `payload_json`
  - `created_at`
  - `updated_at`

## Idempotency
- Treat `session_id` as the canonical idempotency key
- If the same `session_id` is received again, return `200` or `202` without creating a duplicate row
- Do not rely only on application checks; enforce a database unique index

## Performance
- Keep the controller thin
- Use a Form Request for validation
- Insert only one row per request
- Offload enrichments or aggregations to queues if needed later
- Return quickly with a small JSON response

## Security
- Require HTTPS only
- Require a Sanctum PAT with a dedicated service account
- Scope the token to `guest-analytics:write`
- Rotate the token periodically
- Never log the bearer token
- Log only request metadata and sanitized validation failures
- If HMAC is enabled, compute it from the raw request body and compare with `hash_equals`
- Consider allowlisting the sending host or server IP if infrastructure permits

## Response Contract
- `202 Accepted` or `201 Created` on success
- JSON example:

```json
{
  "status": "accepted",
  "session_id": "session-id"
}
```

## Suggested Laravel Pieces
- Migration: create `guest_analytics_events`
- Model: `GuestAnalyticsEvent`
- Form Request: `StoreGuestAnalyticsRequest`
- Controller: `GuestAnalyticsController@store`
- Middleware:
  - `EnsureTokenHasAbility:guest-analytics:write`
  - optional `VerifyGuestAnalyticsSignature`
- Route definition inside `routes/api.php`
- Rate limiter named `guest-analytics`

## Operational Notes
- Keep this endpoint internal-purpose and undocumented in public UI
- Prefer PATs created for a dedicated machine/service user, not a human admin account
- If the sender queues failed requests locally, the endpoint must stay idempotent so retries are safe


ONECBC_ANALYTICS_ENABLED=true
ONECBC_ANALYTICS_URL=https://onecbc.philrice.gov.ph/api/integrations/guest-analytics
ONECBC_ANALYTICS_TOKEN=
ONECBC_ANALYTICS_ALLOWED_HOST=onecbc.philrice.gov.ph
ONECBC_ANALYTICS_AUDIENCE=onecbc
ONECBC_ANALYTICS_SOURCE=cbc-360-tour
ONECBC_ANALYTICS_CONNECT_TIMEOUT_MS=300
ONECBC_ANALYTICS_TIMEOUT_MS=1500
ONECBC_ANALYTICS_HMAC_SECRET=