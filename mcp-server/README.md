# EduPulse AI — ChatGPT MCP Server

This is the first read-only MCP integration for EduPulse AI. It exposes selected student endpoints from the existing Symfony API as tools that an MCP-compatible client can call.

## Included tools

- **get_my_learning_intelligence** — calls GET /api/v1/student/intelligence.
- **list_my_analyses** — calls GET /api/v1/student/analyses.

Both tools forward the caller's EduPulse Bearer access token to the backend. They do not accept student IDs, institute IDs, or identity overrides; the Symfony API must derive access from the authenticated user and enforce its existing authorization rules.

## Requirements

- Node.js 20+
- A running EduPulse Symfony API
- A valid EduPulse access token for a student account
- HTTPS and a stable public URL before connecting a hosted ChatGPT client

## Run locally

From this directory:

    cp .env.example .env
    npm install
    npm run dev

Set EDUPULSE_API_BASE_URL to your backend API base, including its version prefix. If Symfony runs at http://localhost:8000, use http://localhost:8000/api/v1.

Health check: GET http://localhost:8787/health

MCP endpoint: POST http://localhost:8787/mcp

The endpoint requires an Authorization: Bearer header with a valid EduPulse access token and a JSON request body.

Build and run:

    npm run build
    npm start

## Connect an MCP client for local development

Configure the MCP client to use the server's /mcp endpoint and provide a valid EduPulse Bearer token using that client's supported authentication mechanism. For a remote ChatGPT connection, the endpoint must be publicly reachable over HTTPS.

## Important: authentication and publishing

This initial server forwards an existing EduPulse access token; it does **not** implement the OAuth authorization-code flow or OAuth protected-resource metadata required by many hosted ChatGPT app integrations. Do not expose a long-lived student token in a public URL, source code, or shared configuration. Before a public ChatGPT directory release, implement and test OAuth account linking, short-lived scoped tokens, revocation, and account/institute authorization. Verify current OpenAI app submission requirements before publishing.

## Security notes

- Read-only tools only.
- No arbitrary student or institute identifiers are accepted by the tools.
- Browser origins are allowlisted using ALLOWED_ORIGINS.
- Upstream error bodies and access tokens are never returned to the MCP client.
- Use HTTPS in production and store secrets in deployment environment variables, not in Git.
- Ensure the Symfony endpoints enforce tenant and role authorization. MCP is not a replacement for backend authorization.

## Protocol smoke test

After starting the server, send an MCP initialize JSON-RPC request to /mcp with a valid Bearer token. The client should advertise the Streamable HTTP transport and then call tools/list.

    curl -i http://localhost:8787/mcp \
      -H 'Authorization: Bearer YOUR_EDUPULSE_ACCESS_TOKEN' \
      -H 'Content-Type: application/json' \
      -H 'Accept: application/json, text/event-stream' \
      -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"smoke-test","version":"1.0.0"}}}'
