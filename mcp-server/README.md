# EduPulse AI — ChatGPT MCP server

A remote Model Context Protocol (MCP) server for connecting ChatGPT to a student's existing EduPulse account. The current release is deliberately read-only.

## Tools

- `get_my_learning_intelligence` — reads `GET /api/v1/student/intelligence`; requires `learning:read`.
- `list_my_analyses` — reads `GET /api/v1/student/analyses`; requires `analyses:read`.

The tools never accept a student or institute ID. The Symfony API remains responsible for account, role, enrollment, and tenant authorization. The current OAuth sign-in accepts student accounts only.

## Architecture

- Node.js 20+ / TypeScript MCP server using Streamable HTTP at `POST /mcp`.
- Existing Symfony API handles EduPulse login, session refresh, and data authorization.
- PostgreSQL stores OAuth clients, one-time authorization codes, and hashed access/refresh tokens. EduPulse access and refresh credentials are encrypted at rest using AES-256-GCM.
- OAuth access tokens last 30 minutes. MCP refresh tokens last 30 days and rotate on use. Revocation invalidates the token family.
- OAuth uses authorization code + PKCE (S256), validates exact registered redirect URIs, requires explicit consent, and only grants the two read-only scopes.

## Local setup

Requirements: Node.js 20+, PostgreSQL, and a reachable EduPulse Symfony API.

1. Create a dedicated PostgreSQL database/user for the MCP service.
2. Copy `.env.example` to `.env`.
3. Set `EDUPULSE_API_BASE_URL` to your API base URL, including `/api/v1`.
4. Set `DATABASE_URL` and generate a stable encryption key with `openssl rand -base64 32`. Keep the key in your deployment secret manager; losing it invalidates stored OAuth sessions.
5. For local testing, keep `NODE_ENV=development` and local `MCP_PUBLIC_URL`, `MCP_ISSUER_URL`, and `MCP_RESOURCE_URL` values.
6. Run:

   ```bash
   npm install
   npm run dev
   ```

The service creates its OAuth tables on startup. The database user needs permission to create/alter those tables. Health check: `GET http://localhost:8787/health`.

### Run with Docker Compose

1. Copy `.env.example` to `.env`, replace `TOKEN_ENCRYPTION_KEY`, and set a strong `POSTGRES_PASSWORD`.
2. If the Symfony API is running on your host machine, Docker Compose defaults to `http://host.docker.internal:8000/api/v1`. Set `EDUPULSE_API_BASE_URL_DOCKER` in `.env` if your backend uses a different address.
3. Start the MCP service and its private PostgreSQL database:

   ```bash
   docker compose up --build -d
   docker compose logs -f mcp-server
   ```

4. Check `http://localhost:8787/health`. Stop the stack with `docker compose down`. The database volume is retained; use `docker compose down -v` only when you intentionally want to delete the local OAuth database.

Build and run production code:

```bash
npm run build
npm start
```

## OAuth endpoints

- `GET /.well-known/oauth-protected-resource` — protected resource metadata.
- `GET /.well-known/oauth-authorization-server` — authorization server metadata.
- `POST /oauth/register` — dynamic registration for public clients.
- `GET /oauth/authorize` and `POST /oauth/authorize` — EduPulse sign-in and consent.
- `POST /oauth/token` — authorization-code exchange and refresh-token rotation.
- `POST /oauth/revoke` — revoke the token family.
- `POST /mcp` — Streamable HTTP MCP endpoint.
- `GET /health` — health check.

Production redirect URIs are restricted to HTTPS callbacks on `chatgpt.com` / `www.chatgpt.com`. Confirm the exact callback URL supported by the ChatGPT app configuration before deploying. Localhost callbacks are allowed only when `NODE_ENV` is not `production`.

## Deployment

1. Deploy this service behind HTTPS at a stable public hostname.
2. Configure a durable PostgreSQL database, `DATABASE_URL`, `TOKEN_ENCRYPTION_KEY`, `EDUPULSE_API_BASE_URL`, `MCP_PUBLIC_URL`, `MCP_ISSUER_URL`, and `MCP_RESOURCE_URL`.
3. Set `NODE_ENV=production`. Configure `ALLOWED_ORIGINS` narrowly for browser-based clients; it is not an authentication control.
4. Verify `/health` and both OAuth discovery documents over the public HTTPS hostname.
5. Add the remote MCP endpoint in ChatGPT's app/developer configuration, use OAuth as the authentication method, and complete the consent flow with a test student account.
6. Verify both tools, missing/expired/revoked tokens, scope restrictions, upstream token refresh, and cross-student authorization. Confirm that Symfony rejects non-student and unauthorized access.
7. Review the current OpenAI app publishing requirements and complete their review before directory submission.

The Dockerfile builds a production image. It expects the same environment variables and an externally managed PostgreSQL database.

## Security notes and current limitations

- Google sign-in is intentionally not implemented here; it belongs on a separate branch.
- OAuth access/refresh tokens are random opaque values; only their SHA-256 hashes are stored. Upstream EduPulse tokens are encrypted with AES-256-GCM.
- Never commit a real `.env`, database password, or encryption key. Use secret manager/environment injection.
- Use HTTPS for the MCP endpoint, OAuth issuer, and EduPulse API in production.
- Keep the database private and restrict database privileges to this service.
- The authorization page currently uses EduPulse email/password because the existing backend has a login endpoint. A branded first-party login/consent page or a dedicated OAuth provider in the Symfony backend would be preferable before broad public release.
- Add shared rate limiting, operational monitoring, database backup/restore tests, and a production security review before public release. This branch should not be considered production-certified until the CI build and live OAuth/MCP integration tests pass.

## Smoke checks

```bash
curl -fsS http://localhost:8787/health
curl -fsS http://localhost:8787/.well-known/oauth-protected-resource
curl -fsS http://localhost:8787/.well-known/oauth-authorization-server
```

To validate the protocol end-to-end, configure the client through OAuth rather than copying a long-lived EduPulse token into a shared ChatGPT configuration. A successful OAuth exchange should produce a short-lived MCP bearer token; an unauthenticated request to `/mcp` should return HTTP 401 with a `WWW-Authenticate` resource-metadata challenge.
