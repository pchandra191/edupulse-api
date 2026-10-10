import "dotenv/config";
import express, { type Request, type Response } from "express";
import cors from "cors";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import {
  getMcpIdentity,
  initializeOAuth,
  persistRefreshedUpstreamTokens,
  registerOAuthRoutes,
  type McpIdentity,
} from "./oauth.js";

const app = express();
const port = Number(process.env.PORT ?? 8787);
const host = process.env.HOST ?? "0.0.0.0";
const apiBaseUrl = (process.env.EDUPULSE_API_BASE_URL ?? "").replace(/\/$/, "");
const allowedOrigins = new Set(
  (process.env.ALLOWED_ORIGINS ?? "").split(",").map((origin) => origin.trim()).filter(Boolean),
);

app.disable("x-powered-by");
app.use(express.json({ limit: "1mb" }));
app.use(cors({
  origin(origin, callback) {
    if (!origin || allowedOrigins.has(origin)) return callback(null, true);
    return callback(new Error("Origin not allowed"));
  },
  methods: ["GET", "POST", "DELETE", "OPTIONS"],
  allowedHeaders: ["Authorization", "Content-Type", "Mcp-Session-Id", "Mcp-Protocol-Version"],
  exposedHeaders: ["Mcp-Session-Id", "Mcp-Protocol-Version", "WWW-Authenticate"],
}));

registerOAuthRoutes(app);
app.get("/health", (_req, res) => res.json({ status: "ok", service: "edupulse-chatgpt-mcp" }));

function getBearerToken(req: Request): string | null {
  const authorization = req.header("authorization");
  if (!authorization || !/^Bearer\s+\S+$/i.test(authorization)) return null;
  return authorization.replace(/^Bearer\s+/i, "").trim();
}

async function refreshUpstreamToken(identity: McpIdentity): Promise<boolean> {
  if (!identity.upstreamRefreshToken || !apiBaseUrl) return false;
  try {
    const response = await fetch(apiBaseUrl + "/auth/refresh", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ refreshToken: identity.upstreamRefreshToken }),
      signal: AbortSignal.timeout(15000),
    });
    if (!response.ok) return false;
    const payload = await response.json() as Record<string, unknown>;
    const data = payload.data && typeof payload.data === "object" ? payload.data as Record<string, unknown> : payload;
    const accessToken = typeof data.accessToken === "string" ? data.accessToken : "";
    const refreshToken = typeof data.refresh_token === "string" ? data.refresh_token :
      typeof data.refreshToken === "string" ? data.refreshToken : "";
    if (!accessToken || !refreshToken) return false;
    await persistRefreshedUpstreamTokens(identity, accessToken, refreshToken);
    return true;
  } catch {
    return false;
  }
}

async function fetchEduPulse(path: string, identity: McpIdentity): Promise<unknown> {
  if (!apiBaseUrl) throw new Error("EDUPULSE_API_BASE_URL is not configured.");
  const request = () => fetch(apiBaseUrl + path, {
    method: "GET",
    headers: { Accept: "application/json", Authorization: "Bearer " + identity.upstreamAccessToken },
    signal: AbortSignal.timeout(20000),
  });
  let response = await request();
  if (response.status === 401 && await refreshUpstreamToken(identity)) response = await request();
  if (response.status === 401 || response.status === 403) {
    throw new Error("EduPulse rejected this account session. Reconnect your EduPulse account and check your permissions.");
  }
  if (!response.ok) throw new Error("EduPulse API request failed with status " + response.status + ".");
  return response.json();
}

function toolResult(value: unknown) {
  return { content: [{ type: "text" as const, text: JSON.stringify(value, null, 2) }] };
}

function toolError(error: unknown, fallback: string) {
  return {
    isError: true,
    content: [{ type: "text" as const, text: error instanceof Error ? error.message : fallback }],
  };
}

function createMcpServer(identity: McpIdentity) {
  const server = new McpServer({ name: "edupulse-ai", version: "0.2.0" });

  if (identity.scopes.includes("learning:read")) {
    server.tool(
      "get_my_learning_intelligence",
      "Get the authenticated student's EduPulse learning intelligence, including progress, strengths, areas to improve, learning plan, and recommendations. Returns only data authorized for the connected EduPulse student account.",
      {},
      async () => {
        try {
          return toolResult(await fetchEduPulse("/student/intelligence", identity));
        } catch (error) {
          return toolError(error, "Unable to retrieve learning intelligence.");
        }
      },
    );
  }

  if (identity.scopes.includes("analyses:read")) {
    server.tool(
      "list_my_analyses",
      "List the connected student's own EduPulse analyses. This tool does not accept arbitrary student IDs.",
      {},
      async () => {
        try {
          const result = await fetchEduPulse("/student/analyses", identity);
          const serialized = JSON.stringify(result);
          if (serialized.length > 40000) {
            return { content: [{ type: "text" as const, text: JSON.stringify({
              note: "The response exceeded the MCP output limit; a preview is shown.",
              responsePreview: serialized.slice(0, 39000),
            }, null, 2) }] };
          }
          return toolResult(result);
        } catch (error) {
          return toolError(error, "Unable to retrieve analyses.");
        }
      },
    );
  }

  return server;
}

app.post("/mcp", async (req: Request, res: Response) => {
  const token = getBearerToken(req);
  const identity = token ? await getMcpIdentity(token) : null;
  if (!identity) {
    res.setHeader("WWW-Authenticate",
      'Bearer resource_metadata="' + (process.env.MCP_ISSUER_URL ?? new URL(process.env.MCP_PUBLIC_URL ?? "http://localhost:8787/mcp").origin) +
      '/.well-known/oauth-protected-resource", error="invalid_token"');
    res.status(401).json({ error: "unauthorized", message: "Connect your EduPulse account using the OAuth authorization flow." });
    return;
  }

  const server = createMcpServer(identity);
  const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined, enableJsonResponse: true });
  res.on("close", () => {
    void transport.close();
    void server.close();
  });

  try {
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
  } catch {
    if (!res.headersSent) res.status(500).json({ error: "mcp_request_failed", message: "The MCP request could not be processed." });
    await transport.close();
    await server.close();
  }
});

app.get("/mcp", (_req, res) => {
  res.setHeader("Allow", "POST");
  res.status(405).json({ error: "method_not_allowed", message: "This stateless MCP endpoint accepts POST requests." });
});
app.delete("/mcp", (_req, res) => {
  res.setHeader("Allow", "POST");
  res.status(405).json({ error: "method_not_allowed", message: "This stateless MCP endpoint does not use sessions." });
});

initializeOAuth().then(() => {
  app.listen(port, host, () => console.log("EduPulse MCP server listening on " + host + ":" + port));
}).catch((error: unknown) => {
  console.error("EduPulse MCP server failed to start:", error instanceof Error ? error.message : "unknown error");
  process.exitCode = 1;
});
