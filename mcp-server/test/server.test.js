import test, { before, after } from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { createHash } from "node:crypto";
import { createServer } from "node:http";

const baseUrl = "http://127.0.0.1:18787";
let server;
let mockApi;

before(async () => {
  mockApi = createServer((req, res) => {
    if (req.url === "/api/v1/auth/login") {
      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify({
        success: true,
        data: {
          accessToken: "test-upstream-access-token",
          refresh_token: "test-upstream-refresh-token",
          user: { id: "student-test-id", email: "student@example.com", roles: ["ROLE_STUDENT"] },
        },
      }));
      return;
    }
    res.writeHead(404);
    res.end();
  });
  await new Promise((resolve) => mockApi.listen(19999, "127.0.0.1", resolve));

  server = spawn(process.execPath, ["dist/index.js"], {
    cwd: process.cwd(),
    env: {
      ...process.env,
      PORT: "18787",
      HOST: "127.0.0.1",
      NODE_ENV: "test",
      MCP_PUBLIC_URL: baseUrl + "/mcp",
      MCP_ISSUER_URL: baseUrl,
      MCP_RESOURCE_URL: baseUrl + "/mcp",
      EDUPULSE_API_BASE_URL: "http://127.0.0.1:19999/api/v1",
      TOKEN_ENCRYPTION_KEY: "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
      DATABASE_SSL: "false",
    },
    stdio: "ignore",
  });

  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null) throw new Error("MCP server exited before startup.");
    try {
      const response = await fetch(baseUrl + "/health");
      if (response.ok) return;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error("MCP server did not become healthy within 15 seconds.");
});

after(async () => {
  if (server && server.exitCode === null) {
    server.kill("SIGTERM");
    await new Promise((resolve) => server.once("exit", resolve));
  }
  if (mockApi) await new Promise((resolve) => mockApi.close(resolve));
});

test("health endpoint is available", async () => {
  const response = await fetch(baseUrl + "/health");
  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), { status: "ok", service: "edupulse-chatgpt-mcp" });
});

test("OAuth discovery publishes the configured resource and read-only scopes", async () => {
  const response = await fetch(baseUrl + "/.well-known/oauth-protected-resource");
  assert.equal(response.status, 200);
  const metadata = await response.json();
  assert.equal(metadata.resource, baseUrl + "/mcp");
  assert.deepEqual(metadata.authorization_servers, [baseUrl]);
  assert.deepEqual(new Set(metadata.scopes_supported), new Set(["learning:read", "analyses:read"]));

  const authorization = await fetch(baseUrl + "/.well-known/oauth-authorization-server");
  assert.equal(authorization.status, 200);
  const serverMetadata = await authorization.json();
  assert.equal(serverMetadata.authorization_endpoint, baseUrl + "/oauth/authorize");
  assert.deepEqual(serverMetadata.code_challenge_methods_supported, ["S256"]);
});

test("path-aware protected resource metadata is available", async () => {
  const response = await fetch(baseUrl + "/.well-known/oauth-protected-resource/mcp");
  assert.equal(response.status, 200);
  assert.equal((await response.json()).resource, baseUrl + "/mcp");
});

test("MCP rejects requests without a valid OAuth access token", async () => {
  const response = await fetch(baseUrl + "/mcp", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "initialize", params: {
      protocolVersion: "2025-03-26", capabilities: {}, clientInfo: { name: "test", version: "1.0" },
    } }),
  });
  assert.equal(response.status, 401);
  assert.match(response.headers.get("www-authenticate") ?? "", /oauth-protected-resource/);
});

test("dynamic registration rejects untrusted redirect URIs", async () => {
  const response = await fetch(baseUrl + "/oauth/register", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ client_name: "negative-test", redirect_uris: ["https://evil.example/callback"] }),
  });
  assert.equal(response.status, 400);
  assert.equal((await response.json()).error, "invalid_redirect_uri");
});

test("dynamic registration accepts a localhost callback in non-production mode", async () => {
  const response = await fetch(baseUrl + "/oauth/register", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      client_name: "local-smoke-test",
      redirect_uris: ["http://localhost:43210/callback"],
      grant_types: ["authorization_code", "refresh_token"],
      response_types: ["code"],
      token_endpoint_auth_method: "none",
    }),
  });
  assert.equal(response.status, 201);
  const client = await response.json();
  assert.match(client.client_id, /^edupulse_/);
  assert.deepEqual(client.redirect_uris, ["http://localhost:43210/callback"]);
});

test("OAuth authorization-code flow issues a token and revocation invalidates it", async () => {
  const registration = await fetch(baseUrl + "/oauth/register", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      client_name: "full-flow-test",
      redirect_uris: ["http://localhost:43210/callback"],
      grant_types: ["authorization_code", "refresh_token"],
      response_types: ["code"],
      token_endpoint_auth_method: "none",
    }),
  });
  assert.equal(registration.status, 201);
  const client = await registration.json();

  const verifier = "a".repeat(43);
  const challenge = createHash("sha256").update(verifier).digest("base64url");
  const authorizationUrl = new URL(baseUrl + "/oauth/authorize");
  authorizationUrl.search = new URLSearchParams({
    client_id: client.client_id,
    redirect_uri: "http://localhost:43210/callback",
    response_type: "code",
    state: "state-value-123456",
    code_challenge: challenge,
    code_challenge_method: "S256",
    resource: baseUrl + "/mcp",
    scope: "learning:read analyses:read",
  }).toString();
  const authorizePage = await fetch(authorizationUrl);
  assert.equal(authorizePage.status, 200);
  const cookieHeader = authorizePage.headers.get("set-cookie") ?? "";
  const csrf = cookieHeader.match(/edupulse_oauth_csrf=([^;]+)/)?.[1];
  assert.ok(csrf, "authorization page should issue a CSRF cookie");

  const authorizeResponse = await fetch(baseUrl + "/oauth/authorize", {
    method: "POST",
    redirect: "manual",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Cookie: "edupulse_oauth_csrf=" + csrf,
    },
    body: new URLSearchParams({
      client_id: client.client_id,
      redirect_uri: "http://localhost:43210/callback",
      response_type: "code",
      state: "state-value-123456",
      code_challenge: challenge,
      code_challenge_method: "S256",
      resource: baseUrl + "/mcp",
      scope: "learning:read analyses:read",
      csrf,
      email: "student@example.com",
      password: "test-password-only",
      consent: "yes",
    }),
  });
  assert.equal(authorizeResponse.status, 302);
  const callback = new URL(authorizeResponse.headers.get("location"));
  assert.equal(callback.searchParams.get("state"), "state-value-123456");
  const code = callback.searchParams.get("code");
  assert.ok(code);

  const tokenResponse = await fetch(baseUrl + "/oauth/token", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      grant_type: "authorization_code",
      client_id: client.client_id,
      redirect_uri: "http://localhost:43210/callback",
      code,
      code_verifier: verifier,
      resource: baseUrl + "/mcp",
    }),
  });
  assert.equal(tokenResponse.status, 200);
  const tokens = await tokenResponse.json();
  assert.equal(tokens.token_type, "Bearer");
  assert.equal(tokens.expires_in, 1800);
  assert.ok(tokens.access_token);
  assert.ok(tokens.refresh_token);

  const mcpResponse = await fetch(baseUrl + "/mcp", {
    method: "POST",
    headers: {
      Authorization: "Bearer " + tokens.access_token,
      "Content-Type": "application/json",
      Accept: "application/json, text/event-stream",
    },
    body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "initialize", params: {
      protocolVersion: "2025-03-26", capabilities: {}, clientInfo: { name: "oauth-flow-test", version: "1.0" },
    } }),
  });
  assert.equal(mcpResponse.status, 200);

  const revokeResponse = await fetch(baseUrl + "/oauth/revoke", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ token: tokens.access_token }),
  });
  assert.equal(revokeResponse.status, 200);

  const revokedResponse = await fetch(baseUrl + "/mcp", {
    method: "POST",
    headers: {
      Authorization: "Bearer " + tokens.access_token,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ jsonrpc: "2.0", id: 2, method: "initialize", params: {
      protocolVersion: "2025-03-26", capabilities: {}, clientInfo: { name: "oauth-flow-test", version: "1.0" },
    } }),
  });
  assert.equal(revokedResponse.status, 401);
});
