import test, { before, after } from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";

const baseUrl = "http://127.0.0.1:18787";
let server;

before(async () => {
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

after(() => {
  if (server && server.exitCode === null) server.kill("SIGTERM");
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
