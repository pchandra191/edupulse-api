import "dotenv/config";
import express, { type Request, type Response } from "express";
import cors from "cors";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";

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
  exposedHeaders: ["Mcp-Session-Id", "Mcp-Protocol-Version"],
}));

app.get("/health", (_req, res) => res.json({ status: "ok", service: "edupulse-chatgpt-mcp" }));

function getBearerToken(req: Request): string | null {
  const authorization = req.header("authorization");
  if (!authorization || !/^Bearer\s+\S+$/i.test(authorization)) return null;
  return authorization.replace(/^Bearer\s+/i, "").trim();
}

async function fetchEduPulse(path: string, token: string): Promise<unknown> {
  if (!apiBaseUrl) throw new Error("EDUPULSE_API_BASE_URL is not configured.");
  const response = await fetch(apiBaseUrl + path, {
    method: "GET",
    headers: { Accept: "application/json", Authorization: "Bearer " + token },
    signal: AbortSignal.timeout(20_000),
  });
  if (response.status === 401 || response.status === 403) {
    throw new Error("EduPulse rejected the supplied access token. Sign in again or check your permissions.");
  }
  if (!response.ok) throw new Error("EduPulse API request failed with status " + response.status + ".");
  return response.json();
}

function toolResult(value: unknown) {
  return { content: [{ type: "text" as const, text: JSON.stringify(value, null, 2) }] };
}

function createMcpServer(token: string) {
  const server = new McpServer({ name: "edupulse-ai", version: "0.1.0" });

  server.tool(
    "get_my_learning_intelligence",
    "Get the authenticated student's EduPulse learning intelligence, including progress, strengths, areas to improve, learning plan, and recommendations. Returns only data the current EduPulse account is authorized to access.",
    {},
    async () => {
      try {
        return toolResult(await fetchEduPulse("/student/intelligence", token));
      } catch (error) {
        return {
          isError: true,
          content: [{ type: "text" as const, text: error instanceof Error ? error.message : "Unable to retrieve learning intelligence." }],
        };
      }
    },
  );

  server.tool(
    "list_my_analyses",
    "List the authenticated student's own EduPulse analyses. Does not accept arbitrary student IDs.",
    {},
    async () => {
      try {
        const result = await fetchEduPulse("/student/analyses", token);
        const serialized = JSON.stringify(result);
        if (serialized.length > 40_000) {
          return { content: [{ type: "text" as const, text: JSON.stringify({
            note: "The response exceeded the MCP output limit; a preview is shown.",
            responsePreview: serialized.slice(0, 39_000),
          }, null, 2) }] };
        }
        return toolResult(result);
      } catch (error) {
        return {
          isError: true,
          content: [{ type: "text" as const, text: error instanceof Error ? error.message : "Unable to retrieve analyses." }],
        };
      }
    },
  );

  return server;
}

app.post("/mcp", async (req: Request, res: Response) => {
  const token = getBearerToken(req);
  if (!token) {
    res.setHeader("WWW-Authenticate", 'Bearer realm="EduPulse MCP"');
    res.status(401).json({ error: "unauthorized", message: "Provide an EduPulse access token as a Bearer token." });
    return;
  }

  const server = createMcpServer(token);
  const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined, enableJsonResponse: true });
  res.on("close", () => {
    void transport.close();
    void server.close();
  });

  try {
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
  } catch {
    if (!res.headersSent) {
      res.status(500).json({ error: "mcp_request_failed", message: "The MCP request could not be processed." });
    }
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

app.listen(port, host, () => console.log("EduPulse MCP server listening on " + host + ":" + port));
