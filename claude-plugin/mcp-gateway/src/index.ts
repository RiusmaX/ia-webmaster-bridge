/**
 * IA Webmaster Bridge MCP gateway.
 *
 * Local MCP server (stdio transport) launched by Claude Code. It exposes the
 * WordPress adapter routes as MCP tools, signing every call.
 *
 * Important: stdout is reserved for the MCP protocol. Any log message must go
 * through stderr.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { loadConfig } from "./config.js";
import { IawmClient } from "./client.js";
import { registerTools } from "./tools.js";

async function main(): Promise<void> {
  const config = loadConfig();
  const client = new IawmClient(config);

  const server = new McpServer({
    name: "ia-webmaster-bridge",
    version: "0.3.0",
  });

  registerTools(server, client);

  const transport = new StdioServerTransport();
  await server.connect(transport);

  process.stderr.write("[iawm-mcp-gateway] MCP gateway started.\n");
}

main().catch((err: unknown) => {
  process.stderr.write(
    `[iawm-mcp-gateway] fatal error: ${(err as Error).message}\n`,
  );
  process.exit(1);
});
