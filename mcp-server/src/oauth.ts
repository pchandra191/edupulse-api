import { createHash, randomBytes, createCipheriv, createDecipheriv } from "node:crypto";
import type { Express, Request, Response } from "express";
import { Pool } from "pg";

const apiBaseUrl = (process.env.EDUPULSE_API_BASE_URL ?? "").replace(/\/$/, "");
const publicMcpUrl = (process.env.MCP_PUBLIC_URL ?? "").replace(/\/$/, "");
const issuer = (process.env.MCP_ISSUER_URL ?? (publicMcpUrl ? new URL(publicMcpUrl).origin : "")).replace(/\/$/, "");
const resourceId = (process.env.MCP_RESOURCE_URL ?? issuer).replace(/\/$/, "");
const keyText = process.env.TOKEN_ENCRYPTION_KEY ?? "";
const production = process.env.NODE_ENV === "production";
const accessLifetime = 1800;
const refreshLifetime = 2592000;
const scopesAllowed = new Set(["learning:read", "analyses:read"]);
const pool = process.env.DATABASE_URL ? new Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: process.env.DATABASE_SSL === "true" ? { rejectUnauthorized: true } : undefined,
  max: 10,
  connectionTimeoutMillis: 5000,
}) : null;

export type McpIdentity = {
  upstreamAccessToken: string;
  upstreamRefreshToken: string;
  familyId: string;
  scopes: string[];
  resource: string;
  user: { id?: string; email?: string; name?: string };
};

const hash = (value: string) => createHash("sha256").update(value).digest("hex");
const randomToken = (bytes = 32) => randomBytes(bytes).toString("base64url");

function encrypt(value: string): string {
  const key = Buffer.from(keyText, "base64");
  if (key.length !== 32) throw new Error("TOKEN_ENCRYPTION_KEY must encode 32 random bytes.");
  const iv = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", key, iv);
  const encrypted = Buffer.concat([cipher.update(value, "utf8"), cipher.final()]);
  return [iv, cipher.getAuthTag(), encrypted].map((part) => part.toString("base64url")).join(".");
}

function decrypt(value: string): string {
  const key = Buffer.from(keyText, "base64");
  if (key.length !== 32) throw new Error("Invalid token encryption key.");
  const parts = value.split(".");
  if (parts.length !== 3) throw new Error("Malformed encrypted token.");
  const decipher = createDecipheriv("aes-256-gcm", key, Buffer.from(parts[0], "base64url"));
  decipher.setAuthTag(Buffer.from(parts[1], "base64url"));
  return Buffer.concat([decipher.update(Buffer.from(parts[2], "base64url")), decipher.final()]).toString("utf8");
}

function escapeHtml(value: unknown): string {
  return String(value ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[c] ?? c);
}

function getCookie(req: Request, name: string): string | undefined {
  const item = req.headers.cookie?.split(";").map((part) => part.trim()).find((part) => part.startsWith(name + "="));
  return item ? decodeURIComponent(item.slice(name.length + 1)) : undefined;
}

function isAllowedRedirect(value: string): boolean {
  try {
    const url = new URL(value);
    if (url.username || url.password || url.hash) return false;
    if (url.protocol === "https:" && ["chatgpt.com", "www.chatgpt.com"].includes(url.hostname)) return true;
    return !production && ["localhost", "127.0.0.1"].includes(url.hostname) &&
      ["http:", "https:"].includes(url.protocol);
  } catch { return false; }
}

function oauthError(res: Response, error: string, status = 400, description?: string): void {
  res.status(status).setHeader("Cache-Control", "no-store");
  res.json({ error, ...(description ? { error_description: description } : {}) });
}

export async function initializeOAuth(): Promise<void> {
  if (!pool) throw new Error("DATABASE_URL is required for OAuth token storage.");
  if (!apiBaseUrl || !publicMcpUrl || !issuer || !resourceId) {
    throw new Error("Set EDUPULSE_API_BASE_URL, MCP_PUBLIC_URL, and the MCP issuer/resource URLs.");
  }
  if (Buffer.from(keyText, "base64").length !== 32) {
    throw new Error("TOKEN_ENCRYPTION_KEY must be base64 encoding of exactly 32 random bytes.");
  }
  await pool.query(
    "CREATE TABLE IF NOT EXISTS mcp_oauth_clients (" +
    "client_id TEXT PRIMARY KEY, client_name TEXT NOT NULL, redirect_uris JSONB NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());" +
    "CREATE TABLE IF NOT EXISTS mcp_oauth_codes (" +
    "code_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL REFERENCES mcp_oauth_clients(client_id) ON DELETE CASCADE, " +
    "redirect_uri TEXT NOT NULL, code_challenge TEXT NOT NULL, scopes TEXT[] NOT NULL, resource TEXT NOT NULL, " +
    "user_json JSONB NOT NULL, upstream_token_enc TEXT NOT NULL, upstream_refresh_token_enc TEXT, expires_at TIMESTAMPTZ NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());" +
    "CREATE TABLE IF NOT EXISTS mcp_oauth_tokens (" +
    "token_hash TEXT PRIMARY KEY, token_type TEXT NOT NULL CHECK (token_type IN ('access','refresh')), family_id TEXT NOT NULL, " +
    "client_id TEXT NOT NULL REFERENCES mcp_oauth_clients(client_id) ON DELETE CASCADE, scopes TEXT[] NOT NULL, resource TEXT NOT NULL, " +
    "user_json JSONB NOT NULL, upstream_token_enc TEXT NOT NULL, upstream_refresh_token_enc TEXT, expires_at TIMESTAMPTZ NOT NULL, revoked_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());" +
    "CREATE INDEX IF NOT EXISTS idx_mcp_oauth_tokens_expiry ON mcp_oauth_tokens(expires_at);" +
    "CREATE INDEX IF NOT EXISTS idx_mcp_oauth_codes_expiry ON mcp_oauth_codes(expires_at);" +
    "ALTER TABLE mcp_oauth_codes ADD COLUMN IF NOT EXISTS upstream_refresh_token_enc TEXT;" +
    "ALTER TABLE mcp_oauth_tokens ADD COLUMN IF NOT EXISTS upstream_refresh_token_enc TEXT;"
  );
}

export async function getMcpIdentity(accessToken: string): Promise<McpIdentity | null> {
  if (!pool) return null;
  const result = await pool.query(
    "SELECT scopes, resource, user_json, upstream_token_enc, upstream_refresh_token_enc, family_id FROM mcp_oauth_tokens " +
    "WHERE token_hash = $1 AND token_type = 'access' AND revoked_at IS NULL AND expires_at > NOW()",
    [hash(accessToken)],
  );
  const row = result.rows[0];
  if (!row || row.resource !== resourceId) return null;
  try {
    return {
      upstreamAccessToken: decrypt(row.upstream_token_enc as string),
      upstreamRefreshToken: row.upstream_refresh_token_enc ? decrypt(row.upstream_refresh_token_enc as string) : "",
      familyId: row.family_id as string,
      scopes: row.scopes as string[],
      resource: row.resource as string,
      user: row.user_json as McpIdentity["user"],
    };
  } catch { return null; }
}

export async function persistRefreshedUpstreamTokens(identity: McpIdentity, accessToken: string, refreshToken: string): Promise<void> {
  if (!pool) throw new Error("OAuth token storage is unavailable.");
  await pool.query("UPDATE mcp_oauth_tokens SET upstream_token_enc=$1, upstream_refresh_token_enc=$2 WHERE family_id=$3 AND revoked_at IS NULL", [encrypt(accessToken), encrypt(refreshToken), identity.familyId]);
  identity.upstreamAccessToken = accessToken;
  identity.upstreamRefreshToken = refreshToken;
}

export function registerOAuthRoutes(app: Express): void {
  app.get("/.well-known/oauth-protected-resource", (_req, res) => {
    res.setHeader("Cache-Control", "public, max-age=300");
    res.json({
      resource: resourceId,
      authorization_servers: [issuer],
      scopes_supported: [...scopesAllowed],
      resource_documentation: process.env.MCP_DOCUMENTATION_URL || undefined,
    });
  });

  app.get("/.well-known/oauth-authorization-server", (_req, res) => {
    res.setHeader("Cache-Control", "public, max-age=300");
    res.json({
      issuer,
      authorization_endpoint: issuer + "/oauth/authorize",
      token_endpoint: issuer + "/oauth/token",
      revocation_endpoint: issuer + "/oauth/revoke",
      registration_endpoint: issuer + "/oauth/register",
      token_endpoint_auth_methods_supported: ["none"],
      grant_types_supported: ["authorization_code", "refresh_token"],
      response_types_supported: ["code"],
      code_challenge_methods_supported: ["S256"],
      scopes_supported: [...scopesAllowed],
      client_id_metadata_document_supported: false,
    });
  });

  app.post("/oauth/register", async (req, res) => {
    if (!pool) return oauthError(res, "server_error", 500);
    const input = req.body as Record<string, unknown>;
    const redirects = input.redirect_uris;
    if (!Array.isArray(redirects) || redirects.length < 1 || redirects.length > 10 ||
        !redirects.every((uri) => typeof uri === "string" && isAllowedRedirect(uri))) {
      return oauthError(res, "invalid_redirect_uri", 400, "Only HTTPS ChatGPT callback URLs are allowed in production.");
    }
    const grants = Array.isArray(input.grant_types) ? input.grant_types : ["authorization_code", "refresh_token"];
    const responses = Array.isArray(input.response_types) ? input.response_types : ["code"];
    if (!grants.includes("authorization_code") || !responses.includes("code") ||
        (input.token_endpoint_auth_method && input.token_endpoint_auth_method !== "none")) {
      return oauthError(res, "invalid_client_metadata", 400);
    }
    const clientId = "edupulse_" + randomToken(24);
    const clientName = typeof input.client_name === "string" ? input.client_name.slice(0, 120) : "MCP client";
    await pool.query(
      "INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris) VALUES ($1,$2,$3::jsonb)",
      [clientId, clientName, JSON.stringify(redirects)],
    );
    res.status(201).setHeader("Cache-Control", "no-store").json({
      client_id: clientId, client_name: clientName, redirect_uris: redirects,
      grant_types: ["authorization_code", "refresh_token"], response_types: ["code"],
      token_endpoint_auth_method: "none",
    });
  });

  app.get("/oauth/authorize", async (req, res) => {
    if (!pool) return oauthError(res, "server_error", 500);
    const clientId = String(req.query.client_id ?? "");
    const redirectUri = String(req.query.redirect_uri ?? "");
    const result = await pool.query("SELECT redirect_uris, client_name FROM mcp_oauth_clients WHERE client_id = $1", [clientId]);
    const client = result.rows[0];
    if (!client || !Array.isArray(client.redirect_uris) || !client.redirect_uris.includes(redirectUri) || !isAllowedRedirect(redirectUri)) {
      return oauthError(res, "invalid_client", 400);
    }
    const state = req.query.state;
    const challenge = req.query.code_challenge;
    if (req.query.response_type !== "code" || typeof state !== "string" || state.length < 8 ||
        typeof challenge !== "string" || !/^[A-Za-z0-9_-]{43,128}$/.test(challenge) ||
        req.query.code_challenge_method !== "S256") {
      return oauthError(res, "invalid_request", 400, "Authorization code flow with PKCE S256 and state is required.");
    }
    if (req.query.resource !== resourceId) return oauthError(res, "invalid_target", 400);
    const scopes = String(req.query.scope ?? "learning:read analyses:read").split(/\s+/).filter(Boolean);
    if (!scopes.length || scopes.some((scope) => !scopesAllowed.has(scope))) return oauthError(res, "invalid_scope", 400);
    const csrf = randomToken(24);
    res.cookie("edupulse_oauth_csrf", csrf, {
      httpOnly: true, sameSite: "lax", secure: production, path: "/oauth/authorize", maxAge: 600000,
    });
    const values: Record<string, string> = {
      client_id: clientId, redirect_uri: redirectUri, response_type: "code", state,
      code_challenge: challenge, code_challenge_method: "S256", resource: resourceId,
      scope: scopes.join(" "), csrf,
    };
    const hidden = Object.entries(values).map(([key, value]) =>
      '<input type="hidden" name="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '">').join("");
    res.setHeader("Cache-Control", "no-store");
    res.type("html").send(
      '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<title>Connect EduPulse AI</title><style>body{font:16px system-ui;background:#f4f6fb;color:#172033;margin:0;display:grid;min-height:100vh;place-items:center}' +
      '.card{background:white;padding:32px;border-radius:18px;max-width:420px;width:calc(100% - 64px);box-shadow:0 12px 40px #17203314}' +
      'h1{font-size:24px;margin-top:0}.muted{color:#596579;line-height:1.5}.field{display:block;margin:16px 0}' +
      '.field input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd2df;border-radius:8px;font:inherit}' +
      '.check{display:flex;gap:10px;align-items:flex-start;font-size:14px;line-height:1.4;margin:20px 0}' +
      'button{width:100%;padding:13px;background:#2855d9;color:white;border:0;border-radius:9px;font:inherit;font-weight:650;cursor:pointer}.brand{font-weight:750;color:#2855d9}</style></head><body><main class="card">' +
      '<div class="brand">EduPulse AI</div><h1>Connect your EduPulse account</h1><p class="muted"><strong>' +
      escapeHtml(client.client_name) + '</strong> requests read-only access to your EduPulse learning intelligence and analysis history. Sign in with your existing account.</p>' +
      '<form method="post" action="/oauth/authorize">' + hidden +
      '<label class="field">EduPulse email<input type="email" name="email" autocomplete="username" required maxlength="254"></label>' +
      '<label class="field">Password<input type="password" name="password" autocomplete="current-password" required maxlength="1024"></label>' +
      '<label class="check"><input type="checkbox" name="consent" value="yes" required><span>I authorize this connection to read the EduPulse data listed above. I can revoke access later.</span></label>' +
      '<button type="submit">Sign in and authorize</button></form><p class="muted">Your password is sent only to the EduPulse API over HTTPS and is not stored by this service.</p></main></body></html>'
    );
  });

  app.post("/oauth/authorize", async (req, res) => {
    if (!pool) return oauthError(res, "server_error", 500);
    const body = req.body as Record<string, unknown>;
    const csrfCookie = getCookie(req, "edupulse_oauth_csrf");
    res.clearCookie("edupulse_oauth_csrf", { path: "/oauth/authorize" });
    if (!csrfCookie || body.csrf !== csrfCookie || body.consent !== "yes") {
      return oauthError(res, "access_denied", 400, "Consent or security validation failed.");
    }
    const clientId = String(body.client_id ?? "");
    const redirectUri = String(body.redirect_uri ?? "");
    const state = String(body.state ?? "");
    const challenge = String(body.code_challenge ?? "");
    const resource = String(body.resource ?? "");
    const scopes = String(body.scope ?? "").split(/\s+/).filter(Boolean);
    const clientResult = await pool.query("SELECT redirect_uris FROM mcp_oauth_clients WHERE client_id = $1", [clientId]);
    const client = clientResult.rows[0];
    if (!client || !Array.isArray(client.redirect_uris) || !client.redirect_uris.includes(redirectUri) ||
        !isAllowedRedirect(redirectUri) || resource !== resourceId || !state ||
        body.code_challenge_method !== "S256" || !/^[A-Za-z0-9_-]{43,128}$/.test(challenge) ||
        !scopes.length || scopes.some((scope) => !scopesAllowed.has(scope))) return oauthError(res, "invalid_request", 400);
    if (typeof body.email !== "string" || typeof body.password !== "string" || body.password.length > 1024) {
      return oauthError(res, "invalid_request", 400);
    }
    let response: globalThis.Response;
    try {
      response = await fetch(apiBaseUrl + "/auth/login", {
        method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email: body.email, password: body.password }), signal: AbortSignal.timeout(15000),
      });
    } catch {
      return res.status(502).type("html").send("EduPulse sign-in is temporarily unavailable. Please close this window and try again.");
    }
    if (!response.ok) return res.status(401).type("html").send("Sign-in failed. Check your EduPulse email and password, then try again.");
    let payload: Record<string, unknown>;
    try { payload = await response.json() as Record<string, unknown>; } catch {
      return res.status(502).type("html").send("EduPulse returned an invalid sign-in response.");
    }
    const data = payload.data && typeof payload.data === "object" ? payload.data as Record<string, unknown> : payload;
    const upstreamToken = typeof data.accessToken === "string" ? data.accessToken :
      typeof data.access_token === "string" ? data.access_token : "";
    const upstreamRefreshToken = typeof data.refresh_token === "string" ? data.refresh_token :
      typeof data.refreshToken === "string" ? data.refreshToken : "";
    const rawUser = data.user && typeof data.user === "object" ? data.user as Record<string, unknown> : {};
    if (!upstreamToken || !upstreamRefreshToken) return res.status(502).type("html").send("EduPulse sign-in did not return the required session tokens.");
    const roles = Array.isArray(rawUser.roles) ? rawUser.roles.map(String) : [];
    if (!roles.includes("ROLE_STUDENT")) return res.status(403).type("html").send("This ChatGPT connection currently supports student accounts only.");
    const user = {
      ...(rawUser.id !== undefined ? { id: String(rawUser.id) } : {}),
      email: typeof rawUser.email === "string" ? rawUser.email : String(body.email),
      ...(typeof rawUser.name === "string" ? { name: rawUser.name } :
        typeof rawUser.fullName === "string" ? { name: rawUser.fullName } : {}),
    };
    const code = randomToken(32);
    await pool.query(
      "INSERT INTO mcp_oauth_codes (code_hash, client_id, redirect_uri, code_challenge, scopes, resource, user_json, upstream_token_enc, upstream_refresh_token_enc, expires_at) " +
      "VALUES ($1,$2,$3,$4,$5,$6,$7::jsonb,$8,$9,NOW() + INTERVAL '5 minutes')",
      [hash(code), clientId, redirectUri, challenge, scopes, resource, JSON.stringify(user), encrypt(upstreamToken), encrypt(upstreamRefreshToken)],
    );
    const callback = new URL(redirectUri);
    callback.searchParams.set("code", code);
    callback.searchParams.set("state", state);
    res.redirect(302, callback.toString());
  });

  app.post("/oauth/token", async (req, res) => {
    if (!pool) return oauthError(res, "server_error", 500);
    const body = req.body as Record<string, unknown>;
    if (body.client_secret || body.client_assertion) return oauthError(res, "invalid_client", 401);
    const grant = String(body.grant_type ?? "");
    const clientId = String(body.client_id ?? "");
    const resource = String(body.resource ?? "");
    if (resource !== resourceId) return oauthError(res, "invalid_target", 400);
    const client = await pool.query("SELECT client_id FROM mcp_oauth_clients WHERE client_id = $1", [clientId]);
    if (!client.rows[0]) return oauthError(res, "invalid_client", 401);

    if (grant === "authorization_code") {
      const code = String(body.code ?? "");
      const redirectUri = String(body.redirect_uri ?? "");
      const verifier = String(body.code_verifier ?? "");
      if (!code || !redirectUri || !/^[A-Za-z0-9._~-]{43,128}$/.test(verifier)) return oauthError(res, "invalid_request");
      const db = await pool.connect();
      let row: Record<string, any> | undefined;
      try {
        await db.query("BEGIN");
        const found = await db.query(
          "SELECT * FROM mcp_oauth_codes WHERE code_hash=$1 AND client_id=$2 AND redirect_uri=$3 AND resource=$4 AND expires_at>NOW() FOR UPDATE",
          [hash(code), clientId, redirectUri, resource],
        );
        row = found.rows[0];
        if (!row || createHash("sha256").update(verifier).digest("base64url") !== row.code_challenge) {
          await db.query("ROLLBACK");
          return oauthError(res, "invalid_grant", 400, "Authorization code is invalid, expired, already used, or PKCE verification failed.");
        }
        await db.query("DELETE FROM mcp_oauth_codes WHERE code_hash=$1", [hash(code)]);
        await db.query("COMMIT");
      } catch {
        await db.query("ROLLBACK");
        return oauthError(res, "server_error", 500);
      } finally { db.release(); }
      const family = randomToken(24);
      const access = randomToken(32);
      const refresh = randomToken(48);
      await pool.query(
        "INSERT INTO mcp_oauth_tokens (token_hash,token_type,family_id,client_id,scopes,resource,user_json,upstream_token_enc,upstream_refresh_token_enc,expires_at) " +
        "VALUES ($1,'access',$2,$3,$4,$5,$6::jsonb,$7,$8,NOW()+($9*INTERVAL '1 second'))," +
        "($10,'refresh',$2,$3,$4,$5,$6::jsonb,$7,$8,NOW()+($11*INTERVAL '1 second'))",
        [hash(access), family, clientId, row.scopes, resource, JSON.stringify(row.user_json), row.upstream_token_enc, row.upstream_refresh_token_enc, accessLifetime,
          hash(refresh), refreshLifetime],
      );
      return res.setHeader("Cache-Control", "no-store").json({
        access_token: access, token_type: "Bearer", expires_in: accessLifetime, refresh_token: refresh,
        scope: (row.scopes as string[]).join(" "),
      });
    }

    if (grant === "refresh_token") {
      const refreshValue = String(body.refresh_token ?? "");
      if (!refreshValue) return oauthError(res, "invalid_request");
      const db = await pool.connect();
      try {
        await db.query("BEGIN");
        const found = await db.query(
          "UPDATE mcp_oauth_tokens SET revoked_at=NOW() WHERE token_hash=$1 AND token_type='refresh' AND client_id=$2 " +
          "AND resource=$3 AND revoked_at IS NULL AND expires_at>NOW() RETURNING *",
          [hash(refreshValue), clientId, resource],
        );
        const row = found.rows[0];
        if (!row) {
          await db.query("ROLLBACK");
          return oauthError(res, "invalid_grant", 400);
        }
        const access = randomToken(32);
        const refresh = randomToken(48);
        await db.query(
          "INSERT INTO mcp_oauth_tokens (token_hash,token_type,family_id,client_id,scopes,resource,user_json,upstream_token_enc,upstream_refresh_token_enc,expires_at) " +
          "VALUES ($1,'access',$2,$3,$4,$5,$6::jsonb,$7,$8,NOW()+($9*INTERVAL '1 second'))," +
          "($10,'refresh',$2,$3,$4,$5,$6::jsonb,$7,$8,NOW()+($11*INTERVAL '1 second'))",
          [hash(access), row.family_id, clientId, row.scopes, resource, JSON.stringify(row.user_json), row.upstream_token_enc, row.upstream_refresh_token_enc, accessLifetime,
            hash(refresh), refreshLifetime],
        );
        await db.query("COMMIT");
        return res.setHeader("Cache-Control", "no-store").json({
          access_token: access, token_type: "Bearer", expires_in: accessLifetime, refresh_token: refresh,
          scope: (row.scopes as string[]).join(" "),
        });
      } catch {
        await db.query("ROLLBACK");
        return oauthError(res, "server_error", 500);
      } finally { db.release(); }
    }
    return oauthError(res, "unsupported_grant_type", 400);
  });

  app.post("/oauth/revoke", async (req, res) => {
    if (pool && typeof req.body?.token === "string" && req.body.token) {
      await pool.query(
        "UPDATE mcp_oauth_tokens SET revoked_at=NOW() WHERE family_id IN (SELECT family_id FROM mcp_oauth_tokens WHERE token_hash=$1) AND revoked_at IS NULL",
        [hash(req.body.token)],
      );
    }
    res.setHeader("Cache-Control", "no-store").status(200).end();
  });
}

export function oauthChallenge(error = "invalid_token", description = "Connect your EduPulse account to continue."): Record<string, unknown> {
  const safeDescription = description.replace(/["\\]/g, "");
  return {
    isError: true,
    content: [{ type: "text", text: description }],
    _meta: {
      "mcp/www_authenticate": [
        'Bearer resource_metadata="' + issuer + '/.well-known/oauth-protected-resource", error="' +
        error + '", error_description="' + safeDescription + '"',
      ],
    },
  };
}
