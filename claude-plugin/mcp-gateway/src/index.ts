/**
 * Pont MCP IA Webmaster Bridge.
 *
 * Serveur MCP local (transport stdio) lancé par Claude Code. Il expose les
 * routes de l'adaptateur WordPress comme outils MCP, en signant chaque appel.
 *
 * Important : stdout est réservé au protocole MCP. Tout message de log doit
 * passer par stderr.
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

  process.stderr.write("[iawm-mcp-gateway] pont MCP démarré.\n");
}

main().catch((err: unknown) => {
  process.stderr.write(
    `[iawm-mcp-gateway] erreur fatale : ${(err as Error).message}\n`,
  );
  process.exit(1);
});
