/**
 * Declaration of the MCP tools exposed by the gateway.
 *
 * Each tool maps to a route on the adapter. GET tools (system diagnostics)
 * have no body; all others send their parameters in the JSON body of the
 * signed request.
 *
 * Grouped by domain: system, content, media, taxonomies, menus,
 * diagnostics, configuration.
 *
 * Tooling language: this file (titles, descriptions, parameter hints) is
 * in English so the project is usable worldwide. Generated *website*
 * content can be in any language: tools that produce textual content
 * accept an optional `language` parameter (BCP-47 tag, e.g. "en-US",
 * "fr-FR", "es-ES", "de-DE"). When omitted, the WordPress site locale is
 * used. This parameter is a hint to the AI agent (Claude); it is not
 * enforced server-side.
 */

import { z } from "zod";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { IawmClient, type ApiResult } from "./client.js";
import { composePage, composeThemeZone, type SectionInput } from "./divi/compose.js";
import { DIVI_MODULES, DIVI_MODULE_BY_NAME } from "./divi/modules-registry.js";

/** Formats an API result as an MCP tool response. */
function toToolResult(label: string, result: ApiResult) {
  return {
    content: [
      {
        type: "text" as const,
        text: `${label} — HTTP ${result.status}\n${JSON.stringify(result.data, null, 2)}`,
      },
    ],
    isError: !result.ok,
  };
}

/** Accepted write statuses (must match the plugin). */
const WRITE_STATUS = z.enum(["draft", "publish", "pending", "private", "future"]);

/** Optional BCP-47 language tag for generated website content. */
const LANGUAGE_HINT = z
  .string()
  .optional()
  .describe(
    "Optional BCP-47 language tag for the website content to generate (e.g. en-US, fr-FR, es-ES, de-DE, pt-BR, ja-JP). Hint only — defaults to the WordPress site locale. Affects the language of the produced page, NOT the tooling language.",
  );

/* ------------------------------------------------------------------ */
/* System (connection diagnostics)                                     */
/* ------------------------------------------------------------------ */

function registerSystem(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_ping",
    {
      title: "Public diagnostics",
      description:
        "Checks that the adapter is reachable and returns environment versions (WordPress, PHP, Divi). No authentication required.",
    },
    async () => toToolResult("ping", await client.get("/ping")),
  );

  server.registerTool(
    "iawm_status",
    {
      title: "Authenticated status",
      description:
        "Authenticated diagnostics: validates the HMAC-signed connection and returns the adapter state (key identity, kill switch, environment).",
    },
    async () => toToolResult("status", await client.get("/status")),
  );

  server.registerTool(
    "iawm_audit",
    {
      title: "Audit log",
      description:
        "Returns the latest entries in the audit log: every API call is recorded (timestamp, route, outcome, identity, IP).",
    },
    async () => toToolResult("audit", await client.get("/audit")),
  );
}

/* ------------------------------------------------------------------ */
/* Content (pages and posts)                                           */
/* ------------------------------------------------------------------ */

function registerContent(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_content_list",
    {
      title: "List content",
      description: "Lists pages or posts on the site, with pagination, search and status filter.",
      inputSchema: {
        type: z.enum(["post", "page"]).optional().describe("Content type (default: post)"),
        status: z.string().optional().describe("Statuses to include, comma-separated (e.g. publish,draft)"),
        search: z.string().optional().describe("Search term"),
        per_page: z.number().int().min(1).max(100).optional().describe("Results per page (default: 20)"),
        page: z.number().int().min(1).optional().describe("Page number (default: 1)"),
      },
    },
    async (args) => toToolResult("content/list", await client.post("/content/list", args)),
  );

  server.registerTool(
    "iawm_content_get",
    {
      title: "Read a content item",
      description: "Returns the detail of a page or post: full body, metadata, detected builder.",
      inputSchema: {
        id: z.number().int().describe("Content id"),
      },
    },
    async (args) => toToolResult("content/get", await client.post("/content/get", args)),
  );

  server.registerTool(
    "iawm_content_create",
    {
      title: "Create a content item",
      description:
        "Creates a page or a post. Status defaults to 'draft' (publishing is explicit). Gutenberg block markup is normalised unless raw_content=true. dry_run=true simulates without creating.",
      inputSchema: {
        type: z.enum(["post", "page"]).describe("Content type to create"),
        title: z.string().optional().describe("Title"),
        content: z.string().optional().describe("Body (HTML or Gutenberg block markup)"),
        status: WRITE_STATUS.optional().describe("Status (default: draft)"),
        slug: z.string().optional(),
        excerpt: z.string().optional(),
        parent: z.number().int().optional().describe("Parent content id"),
        menu_order: z.number().int().optional(),
        template: z.string().optional().describe("Page template slug"),
        language: LANGUAGE_HINT,
        raw_content: z.boolean().optional().describe("True to skip content normalisation"),
        dry_run: z.boolean().optional().describe("True to simulate without creating"),
      },
    },
    async (args) => {
      const { language: _language, ...payload } = args as Record<string, unknown>;
      return toToolResult("content/create", await client.post("/content/create", payload));
    },
  );

  server.registerTool(
    "iawm_content_update",
    {
      title: "Update a content item",
      description:
        "Updates an existing page or post. Only provided fields are changed. dry_run=true previews without applying.",
      inputSchema: {
        id: z.number().int().describe("Id of the content to update"),
        title: z.string().optional(),
        content: z.string().optional().describe("New body (HTML or Gutenberg blocks)"),
        status: WRITE_STATUS.optional(),
        slug: z.string().optional(),
        excerpt: z.string().optional(),
        parent: z.number().int().optional(),
        menu_order: z.number().int().optional(),
        template: z.string().optional(),
        language: LANGUAGE_HINT,
        raw_content: z.boolean().optional().describe("True to skip content normalisation"),
        dry_run: z.boolean().optional().describe("True to preview without applying"),
      },
    },
    async (args) => {
      const { language: _language, ...payload } = args as Record<string, unknown>;
      return toToolResult("content/update", await client.post("/content/update", payload));
    },
  );
}

/* ------------------------------------------------------------------ */
/* Media                                                               */
/* ------------------------------------------------------------------ */

function registerMedia(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_media_list",
    {
      title: "List media",
      description: "Lists the media library, with pagination, search and MIME-type filter.",
      inputSchema: {
        search: z.string().optional().describe("Search term"),
        mime_type: z.string().optional().describe("MIME-type filter (e.g. image)"),
        per_page: z.number().int().min(1).max(100).optional(),
        page: z.number().int().min(1).optional(),
      },
    },
    async (args) => toToolResult("media/list", await client.post("/media/list", args)),
  );

  server.registerTool(
    "iawm_media_get",
    {
      title: "Read a media item",
      description: "Returns the detail of a media item: URL, MIME type, alternative text, dimensions.",
      inputSchema: {
        id: z.number().int().describe("Media id"),
      },
    },
    async (args) => toToolResult("media/get", await client.post("/media/get", args)),
  );

  server.registerTool(
    "iawm_media_sideload",
    {
      title: "Sideload a media item from a URL",
      description:
        "Downloads a file from a URL and adds it to the media library. dry_run=true simulates without importing.",
      inputSchema: {
        url: z.string().describe("URL of the file to import"),
        title: z.string().optional(),
        alt: z.string().optional().describe("Alternative text"),
        caption: z.string().optional().describe("Caption"),
        description: z.string().optional(),
        attached_to: z.number().int().optional().describe("Id of the content to attach the media to"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("media/sideload", await client.post("/media/sideload", args)),
  );

  server.registerTool(
    "iawm_media_update",
    {
      title: "Update a media item",
      description: "Updates the metadata of a media item (title, alt text, caption, description).",
      inputSchema: {
        id: z.number().int().describe("Media id"),
        title: z.string().optional(),
        alt: z.string().optional(),
        caption: z.string().optional(),
        description: z.string().optional(),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("media/update", await client.post("/media/update", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Taxonomies                                                          */
/* ------------------------------------------------------------------ */

function registerTaxonomy(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_taxonomy_list",
    {
      title: "List terms",
      description: "Lists the terms of a taxonomy (category, post_tag or a custom taxonomy).",
      inputSchema: {
        taxonomy: z.string().describe("Taxonomy slug (e.g. category, post_tag)"),
        search: z.string().optional(),
        per_page: z.number().int().min(1).max(200).optional(),
        page: z.number().int().min(1).optional(),
      },
    },
    async (args) => toToolResult("taxonomy/list", await client.post("/taxonomy/list", args)),
  );

  server.registerTool(
    "iawm_taxonomy_create",
    {
      title: "Create a term",
      description: "Creates a term (category, tag, …) in a taxonomy.",
      inputSchema: {
        taxonomy: z.string().describe("Taxonomy slug"),
        name: z.string().describe("Term name"),
        slug: z.string().optional(),
        description: z.string().optional(),
        parent: z.number().int().optional().describe("Parent term id"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("taxonomy/create", await client.post("/taxonomy/create", args)),
  );

  server.registerTool(
    "iawm_taxonomy_assign",
    {
      title: "Assign terms",
      description:
        "Assigns terms to a content item. `terms` accepts ids (recommended) or names. append=true adds without replacing.",
      inputSchema: {
        id: z.number().int().describe("Content id"),
        taxonomy: z.string().describe("Taxonomy slug"),
        terms: z.array(z.union([z.string(), z.number()])).describe("Terms (ids or names)"),
        append: z.boolean().optional().describe("True to add to existing terms"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("taxonomy/assign", await client.post("/taxonomy/assign", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Navigation menus                                                    */
/* ------------------------------------------------------------------ */

function registerMenu(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_menu_list",
    {
      title: "List menus",
      description: "Lists navigation menus and the theme's menu locations.",
    },
    async () => toToolResult("menu/list", await client.post("/menu/list", {})),
  );

  server.registerTool(
    "iawm_menu_get",
    {
      title: "Read a menu",
      description: "Returns a menu and its items.",
      inputSchema: {
        id: z.number().int().describe("Menu id"),
      },
    },
    async (args) => toToolResult("menu/get", await client.post("/menu/get", args)),
  );

  server.registerTool(
    "iawm_menu_create",
    {
      title: "Create a menu",
      description: "Creates a navigation menu.",
      inputSchema: {
        name: z.string().describe("Menu name"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("menu/create", await client.post("/menu/create", args)),
  );

  server.registerTool(
    "iawm_menu_add_item",
    {
      title: "Add a menu item",
      description:
        "Adds an item to a menu. Provide `url` for a custom link, or `object_id` to point to a page or post.",
      inputSchema: {
        menu_id: z.number().int().describe("Menu id"),
        title: z.string().optional().describe("Item label"),
        url: z.string().optional().describe("URL (for a custom link)"),
        object_id: z.number().int().optional().describe("Id of a page/post (for an internal link)"),
        parent_item: z.number().int().optional().describe("Parent item id"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("menu/add-item", await client.post("/menu/add-item", args)),
  );

  server.registerTool(
    "iawm_menu_remove_item",
    {
      title: "Remove a menu item",
      description: "Removes a menu item.",
      inputSchema: {
        item_id: z.number().int().describe("Menu item id"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("menu/remove-item", await client.post("/menu/remove-item", args)),
  );

  server.registerTool(
    "iawm_menu_assign_location",
    {
      title: "Assign a menu to a location",
      description: "Assigns a menu to one of the theme's menu locations.",
      inputSchema: {
        menu_id: z.number().int().describe("Menu id"),
        location: z.string().describe("Theme location slug"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("menu/assign-location", await client.post("/menu/assign-location", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Diagnostics (read-only)                                             */
/* ------------------------------------------------------------------ */

function registerDiagnostics(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_diagnostics_system",
    {
      title: "System diagnostics",
      description: "Versions (WordPress, PHP, MySQL), active theme, debug state and PHP limits.",
    },
    async () => toToolResult("diagnostics/system", await client.post("/diagnostics/system", {})),
  );

  server.registerTool(
    "iawm_diagnostics_plugins",
    {
      title: "Plugin status",
      description: "Lists installed plugins: version, active/inactive, available update.",
    },
    async () => toToolResult("diagnostics/plugins", await client.post("/diagnostics/plugins", {})),
  );

  server.registerTool(
    "iawm_diagnostics_themes",
    {
      title: "Theme status",
      description: "Lists installed themes and points out the active one.",
    },
    async () => toToolResult("diagnostics/themes", await client.post("/diagnostics/themes", {})),
  );

  server.registerTool(
    "iawm_diagnostics_logs",
    {
      title: "Read the debug log",
      description: "Returns the last lines of the WordPress debug.log (if it exists).",
      inputSchema: {
        lines: z.number().int().min(1).max(1000).optional().describe("Number of lines (default: 100)"),
      },
    },
    async (args) => toToolResult("diagnostics/logs", await client.post("/diagnostics/logs", args)),
  );

  server.registerTool(
    "iawm_diagnostics_smoke",
    {
      title: "Site smoke test",
      description:
        "Operational health check to run AFTER any destructive operation (plugin update, theme switch, core update, restore). Probes the front page over HTTP, scans debug.log for recent fatal errors, verifies the agent user, the kill-switch state and that Divi is still active. Returns per-probe details and an aggregate `healthy: true|false`.",
    },
    async () => toToolResult("diagnostics/smoke", await client.post("/diagnostics/smoke", {})),
  );

  server.registerTool(
    "iawm_diagnostics_check_self",
    {
      title: "Plugin self-check",
      description:
        "Verifies the plugin's own installation invariants: dedicated agent user + role, audit + backup tables exist, at least one credentials record, rotation cron jobs registered, HTTPS configuration. Run this after a plugin upgrade or on a fresh install to confirm everything is wired up.",
    },
    async () => toToolResult("diagnostics/check-self", await client.post("/diagnostics/check-self", {})),
  );
}

/* ------------------------------------------------------------------ */
/* Configuration (settings and users)                                  */
/* ------------------------------------------------------------------ */

function registerConfig(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_config_settings_get",
    {
      title: "Read site settings",
      description: "Returns the mutable site settings (title, tagline, timezone, reading, permalinks, …).",
    },
    async () => toToolResult("config/settings/get", await client.post("/config/settings/get", {})),
  );

  server.registerTool(
    "iawm_config_settings_update",
    {
      title: "Update site settings",
      description:
        "Updates site settings. Only options on the allow-list are accepted; others are rejected. dry_run=true previews.",
      inputSchema: {
        settings: z.record(z.string(), z.unknown()).describe("{ key: value } object of settings to update"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("config/settings/update", await client.post("/config/settings/update", args)),
  );

  server.registerTool(
    "iawm_config_users_list",
    {
      title: "List users",
      description: "Lists user accounts (login, e-mail, roles).",
      inputSchema: {
        search: z.string().optional(),
        per_page: z.number().int().min(1).max(100).optional(),
        page: z.number().int().min(1).optional(),
      },
    },
    async (args) => toToolResult("config/users/list", await client.post("/config/users/list", args)),
  );

  server.registerTool(
    "iawm_config_users_create",
    {
      title: "Create a user",
      description:
        "Creates a user account. Defaults to the 'subscriber' role. With no password provided, a strong password is generated and returned.",
      inputSchema: {
        login: z.string().describe("Login name"),
        email: z.string().describe("E-mail address"),
        password: z.string().optional().describe("Password (generated if absent)"),
        role: z
          .enum(["subscriber", "contributor", "author", "editor", "administrator"])
          .optional()
          .describe("Role (default: subscriber)"),
        display_name: z.string().optional(),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("config/users/create", await client.post("/config/users/create", args)),
  );

  server.registerTool(
    "iawm_config_users_update",
    {
      title: "Update a user",
      description:
        "Updates a user (e-mail, display name, role). The user under which the agent operates cannot be modified.",
      inputSchema: {
        id: z.number().int().describe("User id"),
        email: z.string().optional(),
        display_name: z.string().optional(),
        role: z
          .enum(["subscriber", "contributor", "author", "editor", "administrator"])
          .optional(),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("config/users/update", await client.post("/config/users/update", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Plugins (infrastructure — install/activation)                       */
/* ------------------------------------------------------------------ */

function registerPlugins(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_plugins_info",
    {
      title: "Info on a WP.org plugin",
      description:
        "Fetches metadata of a plugin from the WordPress.org repository from its slug (version, author, compatibility, last update).",
      inputSchema: {
        slug: z.string().describe("WordPress.org slug (e.g. rank-math-seo)"),
      },
    },
    async (args) => toToolResult("plugins/info", await client.post("/plugins/info", args)),
  );

  server.registerTool(
    "iawm_plugins_install",
    {
      title: "Install a plugin",
      description:
        "Installs a plugin from the WordPress.org repository. With activate=true, activates it right after. Returns the path to the installed plugin file. Automatically snapshots the plugin activation state before doing anything (`pre_op_backup_id` in the response) so the install can be rolled back via /backup/restore.",
      inputSchema: {
        slug: z.string().describe("WordPress.org slug of the plugin to install"),
        activate: z
          .boolean()
          .optional()
          .describe("Activate immediately after install (default false)"),
        skip_backup: z
          .boolean()
          .optional()
          .describe("Skip the pre-op backup (use only on retries / chained ops)"),
      },
    },
    async (args) => toToolResult("plugins/install", await client.post("/plugins/install", args)),
  );

  server.registerTool(
    "iawm_plugins_activate",
    {
      title: "Activate a plugin",
      description:
        "Activates an already-installed plugin. `file` is the path returned by diagnostics/plugins (e.g. rank-math-seo/rank-math.php). Snapshots the plugin state beforehand (pre_op_backup_id) so the activation can be rolled back.",
      inputSchema: {
        file: z.string().describe("Plugin file (e.g. rank-math-seo/rank-math.php)"),
        skip_backup: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("plugins/activate", await client.post("/plugins/activate", args)),
  );

  server.registerTool(
    "iawm_plugins_deactivate",
    {
      title: "Deactivate a plugin",
      description:
        "Deactivates a plugin. The IA Webmaster Bridge plugin itself cannot be deactivated via the API (guardrail). Snapshots the plugin state beforehand (pre_op_backup_id).",
      inputSchema: {
        file: z.string().describe("Plugin file to deactivate"),
        skip_backup: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("plugins/deactivate", await client.post("/plugins/deactivate", args)),
  );

  server.registerTool(
    "iawm_plugins_update",
    {
      title: "Update an installed plugin",
      description:
        "Updates an installed plugin to its latest version from WordPress.org. Returns `no_update: true` if already up to date. Otherwise snapshots the plugin state first (pre_op_backup_id) and replays the upgrade. The IA Webmaster Bridge plugin cannot self-update via this endpoint — use the WordPress admin for that.",
      inputSchema: {
        file: z.string().describe("Plugin file to update (e.g. akismet/akismet.php)"),
        skip_backup: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("plugins/update", await client.post("/plugins/update", args)),
  );
}

/* ------------------------------------------------------------------ */
/* SEO (Rank Math / Yoast)                                             */
/* ------------------------------------------------------------------ */

function registerSeo(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_seo_status",
    {
      title: "SEO backend status",
      description:
        "Indicates which SEO plugin is active on the site (Rank Math first, Yoast second) and the list of fields supported by the API.",
    },
    async () => toToolResult("seo/status", await client.post("/seo/status", {})),
  );

  server.registerTool(
    "iawm_seo_page_get",
    {
      title: "Read a page's SEO",
      description:
        "Returns the SEO metadata of a post: meta_title, meta_description, focus_keyword, canonical_url, robots, Open Graph, Twitter.",
      inputSchema: {
        post_id: z.number().int().describe("Post/page id"),
      },
    },
    async (args) => toToolResult("seo/page/get", await client.post("/seo/page/get", args)),
  );

  server.registerTool(
    "iawm_seo_page_update",
    {
      title: "Update a page's SEO",
      description:
        "Updates the SEO metadata of a post. Field names are normalised (backend-independent): meta_title, meta_description, focus_keyword, canonical_url, robots_noindex, robots_nofollow, og_title, og_description, og_image_id, twitter_title, twitter_description, twitter_image_id. dry_run=true previews.",
      inputSchema: {
        post_id: z.number().int().describe("Post/page id"),
        fields: z
          .record(z.string(), z.unknown())
          .describe("Fields to update. Use null/\"\" to clear a field."),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("seo/page/update", await client.post("/seo/page/update", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Divi 5 (layout read + manipulation)                                 */
/* ------------------------------------------------------------------ */

function registerDivi(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_divi_modules_catalog",
    {
      title: "Divi 5 modules catalog",
      description:
        "Returns the auto-generated registry of every Divi 5 module known to the gateway: native + WooCommerce, including each module's block name, Divi 4 shortcode, title, category (structure / module / fullwidth-module / child-module), and the child block names it accepts. Use this to pick the right `blockName` and `innerBlocks` shape when composing in free-form / block mode via iawm_divi_page_compose. Filter by `family` or `category` to narrow the catalog; filter by `q` for a case-insensitive substring match on slug/name/title.",
      inputSchema: {
        family: z
          .enum(["native", "woocommerce", "all"])
          .optional()
          .describe("Restrict to native or woocommerce modules; defaults to all"),
        category: z
          .enum(["structure", "module", "fullwidth-module", "child-module", "all"])
          .optional()
          .describe("Restrict by Divi category; defaults to all"),
        q: z.string().optional().describe("Case-insensitive substring filter on slug, name or title"),
      },
    },
    async (args) => {
      const family = (args as { family?: string }).family ?? "all";
      const category = (args as { category?: string }).category ?? "all";
      const q = ((args as { q?: string }).q ?? "").toLowerCase().trim();
      const items = DIVI_MODULES.filter((m) => {
        if (family !== "all" && m.family !== family) return false;
        if (category !== "all" && m.category !== category) return false;
        if (q !== "") {
          const hay = (m.slug + " " + (m.name ?? "") + " " + (m.title ?? "")).toLowerCase();
          if (!hay.includes(q)) return false;
        }
        return true;
      });
      const text = JSON.stringify(
        {
          total_in_catalog: DIVI_MODULES.length,
          returned: items.length,
          modules: items,
        },
        null,
        2,
      );
      return { content: [{ type: "text" as const, text }] };
    },
  );

  server.registerTool(
    "iawm_divi_module_info",
    {
      title: "Divi 5 module details",
      description:
        "Returns the registry entry for one specific module by its block name (e.g. `divi/button`). Use this to verify a name exists and inspect its category, accepted children and default attribute groups before composing.",
      inputSchema: {
        name: z.string().describe("Block name (e.g. divi/button or divi/woocommerce-product-add-to-cart)"),
      },
    },
    async (args) => {
      const name = (args as { name?: string }).name ?? "";
      const found = DIVI_MODULE_BY_NAME[name];
      if (!found) {
        return {
          content: [{ type: "text" as const, text: JSON.stringify({ ok: false, error: "not_found", name }, null, 2) }],
          isError: true,
        };
      }
      return { content: [{ type: "text" as const, text: JSON.stringify({ ok: true, module: found }, null, 2) }] };
    },
  );

  server.registerTool(
    "iawm_divi_status",
    {
      title: "Divi 5 status",
      description:
        "Indicates whether Divi 5 is active on the site, its version, the current theme, and the ability to parse Gutenberg-format layouts.",
    },
    async () => toToolResult("divi/status", await client.post("/divi/status", {})),
  );

  server.registerTool(
    "iawm_divi_page_read",
    {
      title: "Read a Divi 5 page",
      description:
        "Reads a Divi 5 page and projects its content as a structured tree (sections > rows > columns > modules) with normalised attributes. Three modes: tree (default, hierarchical), flat (linear list with paths), raw (raw parse_blocks). Also returns stats (section count, count by block type).",
      inputSchema: {
        post_id: z.number().int().describe("Post/page id"),
        mode: z
          .enum(["tree", "flat", "raw"])
          .optional()
          .describe("Output format: tree (default) | flat | raw"),
      },
    },
    async (args) => toToolResult("divi/page/read", await client.post("/divi/page/read", args)),
  );

  server.registerTool(
    "iawm_divi_library_list",
    {
      title: "List the Divi library",
      description:
        "Lists items available in the local Divi library (and Cloud if connected): layouts, sections, rows or modules. Returns categories, packs, tags and items.",
      inputSchema: {
        type: z
          .enum(["layout", "section", "row", "module"])
          .optional()
          .describe("Item type to list (default: layout)"),
        exclude: z.array(z.string()).optional().describe("Ids to exclude"),
      },
    },
    async (args) => toToolResult("divi/library/list", await client.post("/divi/library/list", args)),
  );

  server.registerTool(
    "iawm_divi_library_local",
    {
      title: "List locally-saved Divi layouts",
      description:
        "Lists Divi 5 layouts saved in the local library (post_type et_pb_layout). Hybrid workflow: when the user finds an interesting Divi Cloud layout in the visual builder, they click 'Save to Library' — the layout becomes accessible to the API. Filters by category/search, indicates whether each layout is in Divi 5 format.",
      inputSchema: {
        search: z.string().optional().describe("Title search"),
        category: z.string().optional().describe("Layout category slug"),
        per_page: z.number().int().min(1).max(100).optional(),
        page: z.number().int().min(1).optional(),
      },
    },
    async (args) => toToolResult("divi/library/local", await client.post("/divi/library/local", args)),
  );

  server.registerTool(
    "iawm_divi_library_item",
    {
      title: "Fetch a Divi library item",
      description:
        "Fetches the full content of a Divi library item (layout, section, row, module): ready-to-use markup + site global colors + global variables.",
      inputSchema: {
        id: z.union([z.number(), z.string()]).describe("Item id"),
        library_type: z.string().optional().describe("Type (default: layout)"),
        built_for: z.string().optional().describe("Target post type (default: page)"),
        content_type: z.string().optional().describe("Content type (default: layout)"),
      },
    },
    async (args) => toToolResult("divi/library/item", await client.post("/divi/library/item", args)),
  );

  server.registerTool(
    "iawm_divi_cloud_status",
    {
      title: "Divi Cloud status",
      description:
        "Status of the Divi Cloud connection: Elegant Themes license present, account id, presence of a cloudToken (without exposing its value).",
    },
    async () => toToolResult("divi/cloud/status", await client.post("/divi/cloud/status", {})),
  );

  server.registerTool(
    "iawm_divi_global_data",
    {
      title: "Divi design system (global data)",
      description:
        "Fetches the site's Divi design system: global colors (`gcid-*` palette), global variables (typed design tokens — numbers, strings, images, links, colors, fonts), and global fonts (heading + body family). Call this BEFORE generating a layout so the page can reference these tokens for a cohesive look.",
    },
    async () => toToolResult("divi/global-data", await client.post("/divi/global-data", {})),
  );

  server.registerTool(
    "iawm_divi_global_colors_update",
    {
      title: "Update the global color palette",
      description:
        "Replaces the Divi `gcid-*` global color palette. Full-replace semantics: send the WHOLE palette you want (typical pattern: read via iawm_divi_global_data, mutate, send back). Stable ids: keep `gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`, `gcid-link-color` for the default brand slots, and add custom `gcid-<uuid>` entries for extras. dry_run=true previews the impacted ids.",
      inputSchema: {
        global_colors: z
          .record(
            z.string(),
            z.object({
              color: z.string().describe("Hex color (e.g. #ca5500)"),
              lastUpdated: z.string().optional(),
              status: z.enum(["active", "inactive"]).optional(),
              usedInPosts: z.array(z.unknown()).optional(),
            }),
          )
          .describe("Map of gcid -> color entry"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) =>
      toToolResult("divi/global-data/colors/update", await client.post("/divi/global-data/colors/update", args)),
  );

  server.registerTool(
    "iawm_divi_global_fonts_update",
    {
      title: "Update the global heading + body fonts",
      description:
        "Sets the site-wide heading and body font families. Either field may be omitted to leave it unchanged. Values are family names exactly as Divi expects ('Open Sans', 'Roboto', 'Inter', 'Arial', 'Georgia', 'Playfair Display', …) — Google Fonts names work, plus the standard system fonts.",
      inputSchema: {
        heading_font: z.string().optional().describe("Heading family"),
        body_font: z.string().optional().describe("Body family"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) =>
      toToolResult("divi/global-data/fonts/update", await client.post("/divi/global-data/fonts/update", args)),
  );

  server.registerTool(
    "iawm_divi_global_variables_update",
    {
      title: "Update the global variables (design tokens)",
      description:
        "Replaces Divi's typed design-token map. Six buckets: numbers, strings, images, links, colors, fonts. Each entry: { label, value, order, status }. Ids are `gvid-<uuid>`. Full-replace semantics — read with iawm_divi_global_data, mutate, send back. Common bucket conventions: `numbers` for sizes/radii ('12px', '0.75rem'), `colors` for non-palette accents, `strings` for reusable labels.",
      inputSchema: {
        global_variables: z
          .object({
            numbers: z.record(z.string(), z.unknown()).optional(),
            strings: z.record(z.string(), z.unknown()).optional(),
            images: z.record(z.string(), z.unknown()).optional(),
            links: z.record(z.string(), z.unknown()).optional(),
            colors: z.record(z.string(), z.unknown()).optional(),
            fonts: z.record(z.string(), z.unknown()).optional(),
          })
          .describe("Buckets keyed by category"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) =>
      toToolResult("divi/global-data/variables/update", await client.post("/divi/global-data/variables/update", args)),
  );

  server.registerTool(
    "iawm_divi_branding_get",
    {
      title: "Read Divi branding (logo / favicon)",
      description:
        "Returns the branding-related sub-keys of the `et_divi` WP option: divi_logo, divi_favicon, plus dark/mobile/tablet/phone logo variants. These keys live OUTSIDE the narrow theme-options allow-list and are managed through this dedicated endpoint.",
    },
    async () => toToolResult("divi/branding/get", await client.post("/divi/branding/get", {})),
  );

  server.registerTool(
    "iawm_divi_branding_update",
    {
      title: "Update Divi branding (logo / favicon)",
      description:
        "Updates branding keys in the `et_divi` WP option (divi_logo, divi_favicon, divi_logo_dark, divi_logo_mobile, divi_logo_tablet, divi_logo_phone). Pass a `branding` object; the wrapper validates each key against the allow-list and reports outcomes under `applied` / `rejected`. URLs are sanitised via esc_url_raw. Auto-snapshots the entire `et_divi` option before writing (pre_op_backup_id) so a botched logo can be rolled back via /backup/restore.",
      inputSchema: {
        branding: z
          .record(z.string(), z.string())
          .describe("{ divi_logo: '<URL>', divi_favicon: '<URL>', ... }"),
        dry_run: z.boolean().optional(),
        skip_backup: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("divi/branding/update", await client.post("/divi/branding/update", args)),
  );

  server.registerTool(
    "iawm_divi_theme_options_get",
    {
      title: "Read Divi's theme options (ePanel)",
      description:
        "Returns the full set of Divi theme options stored under the `et_divi` option key — site logo, favicon, header/footer integration code, performance switches, layout settings, etc. The shape is what Divi's ePanel exposes; use this to inspect before calling iawm_divi_theme_options_update.",
    },
    async () => toToolResult("divi/theme-options/get", await client.post("/divi/theme-options/get", {})),
  );

  server.registerTool(
    "iawm_divi_theme_options_update",
    {
      title: "Update Divi's theme options",
      description:
        "Updates one or more theme-options keys. Divi enforces a strict allow-list of 17 customizer keys: divi_blog_style, divi_disable_translations, heading_font, body_font, heading_font_weight, body_font_weight, body_font_height, body_font_size, body_header_size, content_width, accent_color, et_pb_static_css_file, et_pb_css_in_footer, gutter_width, vertical_nav, header_style, color_schemes. Pass a bag of {key: value}; the wrapper loops over Divi's single-key endpoint. Values are coerced to string. Site logo / favicon and other ePanel options OUTSIDE this allow-list are not writable through this route (they live in the `et_divi` WP option behind the Customizer).",
      inputSchema: {
        options: z.record(z.string(), z.unknown()).describe("Allowlisted keys to set; see description"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) =>
      toToolResult("divi/theme-options/update", await client.post("/divi/theme-options/update", args)),
  );

  // -------- Theme Builder --------

  server.registerTool(
    "iawm_divi_theme_builder_list",
    {
      title: "List Theme Builder templates",
      description:
        "Lists Divi Theme Builder templates (header/footer/body assigned to conditions). Enriched with the titles of the linked physical layouts. live=true (default) = published templates.",
      inputSchema: {
        live: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("divi/theme-builder/list", await client.post("/divi/theme-builder/list", args)),
  );

  server.registerTool(
    "iawm_divi_theme_builder_layout_create",
    {
      title: "Create a Theme Builder layout (header/body/footer)",
      description:
        "Creates a physical layout (et_header_layout / et_body_layout / et_footer_layout) with its Divi 5 content. Accepts `content` (serialised string) OR `blocks` (parse_blocks array). Returns the id, to be used afterwards in theme_builder_template_update.",
      inputSchema: {
        zone: z.enum(["header", "body", "footer"]).describe("Layout zone"),
        title: z.string().optional(),
        content: z.string().optional().describe("Serialised post_content (alternative to blocks)"),
        blocks: z.array(z.record(z.string(), z.unknown())).optional().describe("Array of parse_blocks blocks"),
      },
    },
    async (args) => toToolResult("divi/theme-builder/layout/create", await client.post("/divi/theme-builder/layout/create", args)),
  );

  server.registerTool(
    "iawm_divi_theme_builder_layout_read",
    {
      title: "Read a Theme Builder layout",
      description:
        "Reads the content of a Theme Builder layout (header/body/footer) as a structured Divi tree, identical to iawm_divi_page_read but validating the post_type.",
      inputSchema: {
        post_id: z.number().int(),
        mode: z.enum(["tree", "flat", "raw"]).optional(),
      },
    },
    async (args) => toToolResult("divi/theme-builder/layout/read", await client.post("/divi/theme-builder/layout/read", args)),
  );

  server.registerTool(
    "iawm_divi_theme_builder_setup_site_defaults",
    {
      title: "Set up site header/footer in one call",
      description:
        "High-level wrapper: creates the Theme Builder container + a default template with header/body/footer (when provided) and assigns it as the site default (applies to any post/page without override). Refuses if a default template already exists unless replace_existing=true. Each zone is an object {title?, content? | blocks?}.",
      inputSchema: {
        title: z.string().optional().describe("Template title (default: Default Site Template)"),
        header: z.record(z.string(), z.unknown()).optional().describe("{title?, content?, blocks?}"),
        body: z.record(z.string(), z.unknown()).optional(),
        footer: z.record(z.string(), z.unknown()).optional(),
        assign_default: z.boolean().optional().describe("Mark as site default (default true)"),
        replace_existing: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("divi/theme-builder/setup-site-defaults", await client.post("/divi/theme-builder/setup-site-defaults", args)),
  );

  server.registerTool(
    "iawm_divi_theme_builder_template_assign",
    {
      title: "Assign a Theme Builder template to conditions",
      description:
        "Sets the use_on (where the template applies) and exclude_from (exceptions) conditions. Examples: 'default', 'singular:page', 'singular:post', 'page:123', 'archive:category'.",
      inputSchema: {
        template_id: z.number().int(),
        use_on: z.array(z.string()).optional(),
        exclude_from: z.array(z.string()).optional(),
        live: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("divi/theme-builder/template/assign", await client.post("/divi/theme-builder/template/assign", args)),
  );

  server.registerTool(
    "iawm_divi_theme_builder_template_delete",
    {
      title: "Delete a Theme Builder template",
      description: "Deletes a template (does NOT delete the linked physical layouts — use content/get + delete on the ids for that).",
      inputSchema: {
        template_id: z.number().int(),
        live: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("divi/theme-builder/template/delete", await client.post("/divi/theme-builder/template/delete", args)),
  );

  // -------- Composers (recommended way to generate pages) --------

  server.registerTool(
    "iawm_divi_page_compose",
    {
      title: "Compose and write a Divi 5 page (recommended)",
      description:
        "PRIMARY ENTRY POINT to generate a Divi 5 page: takes a `sections` array where each section is either (1) a parametric PATTERN { pattern: 'hero' | 'features3col' | 'ctaBanner' | 'imageTextSplit' | 'testimonials' | 'faqAccordion' | 'numbersBar' | 'videoSection' | 'contactSection' | 'pricing3col' | 'teamGrid' | 'headerSimple' | 'footerStandard', options: {...} }, OR (2) a FREE-FORM SECTION { section: { background?, spacing?, rows: [{ structure: '1_2,1_2', wrapMobile?: true, columns: [[{module:'text', html:'...'}, ...], ...] }] } }, OR (3) a RAW BLOCK { block: <GutenbergBlock JSON> }. Free-form module names supported: text, blurb, cta, image, button, heading, number-counter, circle-counter, testimonial, team-member, gallery, video, audio, code, divider, icon, toggle, signup, map, menu, fullwidth-menu, search, breadcrumbs, post-title, post-content, post-navigation, comments, accordion, tabs, slider, contact-form, pricing-tables, icon-list, social-media-follow, counters. The composer assembles everything and writes via divi/page/write. NEVER use an intermediate script — call this tool directly.",
      inputSchema: {
        post_id: z.number().int().describe("Target page id"),
        sections: z
          .array(z.record(z.string(), z.unknown()))
          .describe("Sections array (mix of patterns / free-form / blocks)"),
        language: LANGUAGE_HINT,
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => {
      const sections = (args.sections ?? []) as unknown as SectionInput[];
      try {
        const root = composePage(sections);
        const payload: Record<string, unknown> = { post_id: args.post_id, blocks: [root] };
        if (args.dry_run) payload.dry_run = true;
        return toToolResult(
          "divi/page/compose",
          await client.post("/divi/page/write", payload),
        );
      } catch (err) {
        return {
          content: [{ type: "text" as const, text: `Composition error: ${(err as Error).message}` }],
          isError: true,
        };
      }
    },
  );

  server.registerTool(
    "iawm_divi_theme_builder_compose",
    {
      title: "Compose and apply a Theme Builder template (recommended)",
      description:
        "PRIMARY ENTRY POINT to generate a Theme Builder: takes `header_sections`, `body_sections` and/or `footer_sections` (arrays of SectionInput, same grammar as iawm_divi_page_compose: patterns, free-form or blocks). Composes each zone and calls theme-builder/setup-site-defaults. assign_default=true by default (global template). replace_existing=true to overwrite an existing default template.",
      inputSchema: {
        title: z.string().optional().describe("Template title (default: Default Site Template)"),
        header_sections: z.array(z.record(z.string(), z.unknown())).optional(),
        body_sections: z.array(z.record(z.string(), z.unknown())).optional(),
        footer_sections: z.array(z.record(z.string(), z.unknown())).optional(),
        language: LANGUAGE_HINT,
        assign_default: z.boolean().optional(),
        replace_existing: z.boolean().optional(),
      },
    },
    async (args) => {
      try {
        const payload: Record<string, unknown> = {};
        if (args.title) payload.title = args.title;
        if (args.assign_default !== undefined) payload.assign_default = args.assign_default;
        if (args.replace_existing !== undefined) payload.replace_existing = args.replace_existing;

        for (const zone of ["header", "body", "footer"] as const) {
          const sections = args[`${zone}_sections` as const] as
            | undefined
            | unknown[];
          if (sections && sections.length > 0) {
            const root = composeThemeZone(sections as unknown as SectionInput[]);
            payload[zone] = { blocks: [root] };
          }
        }

        return toToolResult(
          "divi/theme-builder/compose",
          await client.post("/divi/theme-builder/setup-site-defaults", payload),
        );
      } catch (err) {
        return {
          content: [{ type: "text" as const, text: `Composition error: ${(err as Error).message}` }],
          isError: true,
        };
      }
    },
  );

  server.registerTool(
    "iawm_divi_page_write",
    {
      title: "Write a Divi 5 layout",
      description:
        "Writes a Divi 5 layout into a post. Two accepted formats: `content` (post_content string already serialised with the wp:divi/* comments) OR `blocks` (array of blocks in parse_blocks format). If the root wp:divi/placeholder wrapper is missing, it is added automatically. The `_et_pb_use_builder` meta is set when absent. dry_run=true previews without writing.",
      inputSchema: {
        post_id: z.number().int().describe("Target post/page id"),
        content: z
          .string()
          .optional()
          .describe("Already-serialised post_content (string with wp:divi/* comments)"),
        blocks: z
          .array(z.record(z.string(), z.unknown()))
          .optional()
          .describe("Array of blocks in parse_blocks format (alternative to content)"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("divi/page/write", await client.post("/divi/page/write", args)),
  );
}

/**
 * Registers every gateway tool on the MCP server.
 *
 * @param server MCP server.
 * @param client Signed client to the adapter.
 */
/* ------------------------------------------------------------------ */
/* Cron (Phase 4 — WP-Cron management)                                 */
/* ------------------------------------------------------------------ */

function registerCron(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_cron_list",
    {
      title: "List scheduled events",
      description:
        "Returns every queued WP-Cron event with its hook, args, next-run timestamp, recurrence slug and interval. Optional `hook` filter narrows the list to one hook.",
      inputSchema: {
        hook: z.string().optional().describe("Optional hook name to filter on"),
      },
    },
    async (args) => toToolResult("cron/list", await client.post("/cron/list", args)),
  );

  server.registerTool(
    "iawm_cron_schedules",
    {
      title: "List available recurrence slugs",
      description:
        "Returns the recurrence slugs registered on the site (hourly, daily, plus any custom ones declared by themes/plugins) with their interval in seconds and display label.",
      inputSchema: {},
    },
    async () => toToolResult("cron/schedules", await client.post("/cron/schedules", {})),
  );

  server.registerTool(
    "iawm_cron_run",
    {
      title: "Run a scheduled event now",
      description:
        "Fires a queued hook immediately and re-schedules it if it was recurring. `args` must match the queued event exactly (same array shape) — use iawm_cron_list to find the right values.",
      inputSchema: {
        hook: z.string().describe("Hook to fire"),
        args: z
          .array(z.unknown())
          .optional()
          .describe("Positional args matching the queued event"),
      },
    },
    async (args) => toToolResult("cron/run", await client.post("/cron/run", args)),
  );

  server.registerTool(
    "iawm_cron_schedule",
    {
      title: "Schedule an event",
      description:
        "Adds a one-off (no `schedule`) or recurring (`schedule: 'hourly'|'daily'|...`) event. Defaults to firing 60 seconds from now. The hook itself must already have a PHP listener somewhere on the site — this only queues the call.",
      inputSchema: {
        hook: z.string().describe("Hook to schedule"),
        schedule: z
          .string()
          .optional()
          .describe("Recurrence slug from /cron/schedules (omit for a one-off event)"),
        timestamp: z
          .number()
          .int()
          .optional()
          .describe("Unix timestamp for the first run (default: now+60s)"),
        args: z.array(z.unknown()).optional().describe("Positional args for the hook"),
      },
    },
    async (args) => toToolResult("cron/schedule", await client.post("/cron/schedule", args)),
  );

  server.registerTool(
    "iawm_cron_unschedule",
    {
      title: "Unschedule an event",
      description:
        "Removes a scheduled event. With `timestamp`: removes a specific occurrence (args must match). Without `timestamp`: removes every occurrence of the hook.",
      inputSchema: {
        hook: z.string().describe("Hook to unschedule"),
        timestamp: z
          .number()
          .int()
          .optional()
          .describe("Specific occurrence to remove; omit to remove all occurrences of the hook"),
        args: z.array(z.unknown()).optional().describe("Args of the specific occurrence"),
      },
    },
    async (args) => toToolResult("cron/unschedule", await client.post("/cron/unschedule", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Database (Phase 4)                                                  */
/* ------------------------------------------------------------------ */

function registerDatabase(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_database_info",
    {
      title: "Database overview",
      description:
        "Lists every table in the WordPress database with engine, approximate row count, data size and index size. Read-only. Useful as a starting point before /database/export or /database/search-replace.",
      inputSchema: {},
    },
    async () => toToolResult("database/info", await client.post("/database/info", {})),
  );

  server.registerTool(
    "iawm_database_export",
    {
      title: "Export selected tables",
      description:
        "Dumps the named tables as SQL into a backup record (the same store the auto pre-op backups use). Returns the new backup_id; retrieve the payload via iawm_backup_get with include_payload=true, or replay it via iawm_backup_restore.",
      inputSchema: {
        tables: z
          .array(z.string())
          .describe("Fully-qualified table names (e.g. wp_options, wp_postmeta)"),
        label: z.string().optional().describe("Human-readable label"),
      },
    },
    async (args) => toToolResult("database/export", await client.post("/database/export", args)),
  );

  server.registerTool(
    "iawm_database_query",
    {
      title: "Run a SELECT query",
      description:
        "Runs an ad-hoc read-only SQL query. Strict validation: must start with SELECT (or WITH ... SELECT); no `;`, no INTO OUTFILE / INTO DUMPFILE / LOAD_FILE / BENCHMARK / SLEEP(); a LIMIT is forcibly appended (max 200 by default). Returns rows as an array of associative records.",
      inputSchema: {
        sql: z.string().describe("SELECT (or WITH ... SELECT) statement"),
        limit: z
          .number()
          .int()
          .optional()
          .describe("Row cap (1-200, default 200)"),
      },
    },
    async (args) => toToolResult("database/query", await client.post("/database/query", args)),
  );

  server.registerTool(
    "iawm_database_search_replace",
    {
      title: "Serialization-safe search/replace",
      description:
        "Replaces a string by another across a fixed allow-list of (table, column) pairs (options.option_value, posts.post_content/excerpt/title, *meta.meta_value, comments.comment_content). PHP-serialised payloads are walked recursively so length counters stay valid. Always call once with `dry_run: true` first to see counts + samples. A real (non-dry_run) call requires a two-step confirmation: the first call without `confirmation_token` returns `requires_confirmation: true` with a fresh token; re-issue the EXACT SAME body with `confirmation_token: <that token>` to apply.",
      inputSchema: {
        search: z.string().describe("String to find"),
        replace: z.string().describe("Replacement string"),
        targets: z
          .array(z.tuple([z.string(), z.string()]))
          .optional()
          .describe("Optional explicit [table, column] pairs; defaults to the full allow-list"),
        dry_run: z
          .boolean()
          .optional()
          .describe("True to report counts + samples without applying (RECOMMENDED first pass)"),
        confirmation_token: z
          .string()
          .optional()
          .describe("Token returned by the first non-dry_run call. Required to actually apply."),
      },
    },
    async (args) =>
      toToolResult("database/search-replace", await client.post("/database/search-replace", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Core WordPress update (Phase 4)                                     */
/* ------------------------------------------------------------------ */

function registerCore(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_core_info",
    {
      title: "WordPress core version info",
      description:
        "Returns the current WordPress version, the PHP version running on the server, and whether a core update is available (with the target version + PHP/MySQL requirements). Read-only.",
      inputSchema: {},
    },
    async () => toToolResult("core/info", await client.post("/core/info", {})),
  );

  server.registerTool(
    "iawm_core_update",
    {
      title: "Apply WordPress core update",
      description:
        "Updates WordPress to the latest version offered by WP.org for this site. The most destructive operation in the API — runs a PHP version pre-flight, snapshots the plugin state (pre_op_backup_id), and only then invokes Core_Upgrader. A real (non-dry_run) update requires a TWO-STEP flow: the first call returns `requires_confirmation: true` + a fresh `confirmation_token` + a summary (current_version, would_update_to, php_required); re-issue the SAME body with the token in `confirmation_token` to apply. Tokens are single-use, expire after 5 minutes, and are bound to the exact body.",
      inputSchema: {
        dry_run: z
          .boolean()
          .optional()
          .describe("True to preview what would happen without applying (no token needed)"),
        skip_backup: z
          .boolean()
          .optional()
          .describe("Skip the pre-op snapshot (use only on chained ops)"),
        confirmation_token: z
          .string()
          .optional()
          .describe("Token returned by the first non-dry_run call. Required to actually apply."),
      },
    },
    async (args) => toToolResult("core/update", await client.post("/core/update", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Themes (Phase 4 — install/activate/update)                          */
/* ------------------------------------------------------------------ */

function registerThemes(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_themes_info",
    {
      title: "Theme metadata",
      description:
        "Fetches a WordPress.org theme's metadata (version, author, screenshot, requirements, last update) from the official theme repository. Read-only.",
      inputSchema: {
        slug: z.string().describe("WordPress.org theme slug (e.g. twentytwentyfour)"),
      },
    },
    async (args) => toToolResult("themes/info", await client.post("/themes/info", args)),
  );

  server.registerTool(
    "iawm_themes_list",
    {
      title: "List installed themes",
      description:
        "Lists themes installed on the site. For each theme: stylesheet (slug), name, version, author, parent template if any, active flag, update available + new_version when applicable. The active theme and parent template are also returned at the top level.",
      inputSchema: {},
    },
    async () => toToolResult("themes/list", await client.post("/themes/list", {})),
  );

  server.registerTool(
    "iawm_themes_install",
    {
      title: "Install a theme",
      description:
        "Installs a theme from the official WordPress.org repository. With activate=true, switches the active theme to it in the same call. Automatically takes a pre-op snapshot of the theme-related options before doing anything (returned as pre_op_backup_id). Source is restricted to WP.org — no arbitrary URL.",
      inputSchema: {
        slug: z.string().describe("WordPress.org theme slug"),
        activate: z
          .boolean()
          .optional()
          .describe("Activate immediately after install (default false)"),
        skip_backup: z
          .boolean()
          .optional()
          .describe("Skip the pre-op backup (use only on retries / chained ops)"),
      },
    },
    async (args) => toToolResult("themes/install", await client.post("/themes/install", args)),
  );

  server.registerTool(
    "iawm_themes_activate",
    {
      title: "Activate a theme",
      description:
        "Switches the site's active theme to an already-installed theme. CAUTION: changes the entire frontend. The previous theme settings (template, stylesheet, theme_mods) are snapshotted automatically beforehand; restore the returned pre_op_backup_id to roll back.",
      inputSchema: {
        stylesheet: z.string().describe("Stylesheet (slug) of the theme to activate"),
        skip_backup: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("themes/activate", await client.post("/themes/activate", args)),
  );

  server.registerTool(
    "iawm_themes_update",
    {
      title: "Update an installed theme",
      description:
        "Updates an installed theme to its latest version from WordPress.org. Returns no_update=true if already up to date. Otherwise snapshots the theme-related options first (pre_op_backup_id) and replays the upgrade.",
      inputSchema: {
        stylesheet: z.string().describe("Stylesheet (slug) of the theme to update"),
        skip_backup: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("themes/update", await client.post("/themes/update", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Backup (snapshot + restore — Phase 5.2)                             */
/* ------------------------------------------------------------------ */

function registerBackup(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_backup_list",
    {
      title: "List backups",
      description:
        "Lists snapshots taken by the adapter (manual or auto-triggered before destructive operations). Each entry surfaces id, kind (options / plugins_state / tables), label, timestamp, payload size, and restore state. Use this to find a pre-op snapshot id before /backup/restore.",
      inputSchema: {
        limit: z.number().int().optional().describe("Per-page (1-100, default 50)"),
        offset: z.number().int().optional().describe("Offset (>=0, default 0)"),
      },
    },
    async (args) => toToolResult("backup/list", await client.post("/backup/list", args)),
  );

  server.registerTool(
    "iawm_backup_get",
    {
      title: "Get a backup",
      description:
        "Returns a backup record. Set include_payload=true to also read the snapshot content (options dict / plugin state / SQL dump). Large payloads can be skipped by leaving include_payload false.",
      inputSchema: {
        id: z.number().int().describe("Backup id"),
        include_payload: z
          .boolean()
          .optional()
          .describe("True to include the snapshot payload (defaults to false)"),
      },
    },
    async (args) => toToolResult("backup/get", await client.post("/backup/get", args)),
  );

  server.registerTool(
    "iawm_backup_create",
    {
      title: "Create a manual backup",
      description:
        "Takes a manual snapshot. kind='options' captures the given option_names (array of WP option keys). kind='plugins_state' captures active_plugins and the installed plugin list. kind='tables' dumps the given tables to SQL (heavy — use sparingly). Useful before a series of changes or before invoking external tooling.",
      inputSchema: {
        kind: z.enum(["options", "plugins_state", "tables"]).describe("Snapshot kind"),
        label: z.string().optional().describe("Human-readable label"),
        option_names: z
          .array(z.string())
          .optional()
          .describe("For kind=options: WP option keys to snapshot"),
        tables: z
          .array(z.string())
          .optional()
          .describe("For kind=tables: fully-qualified table names (e.g. wp_options)"),
      },
    },
    async (args) => toToolResult("backup/create", await client.post("/backup/create", args)),
  );

  server.registerTool(
    "iawm_backup_restore",
    {
      title: "Restore a backup",
      description:
        "Restores a previously taken snapshot. dry_run=true returns the diff WITHOUT applying — no token needed. A real (non-dry_run) restore is a TWO-STEP operation: first call without `confirmation_token` returns `requires_confirmation: true` with a fresh token AND a preview summary; re-issue the SAME body with `confirmation_token: <that token>` to actually apply. Tokens are single-use, expire after 5 minutes and are bound to the exact body — issued for one restore, they cannot be replayed for another.",
      inputSchema: {
        id: z.number().int().describe("Backup id to restore"),
        dry_run: z
          .boolean()
          .optional()
          .describe("True to preview the diff without applying (RECOMMENDED first pass, no token needed)"),
        confirmation_token: z
          .string()
          .optional()
          .describe("Token returned by the first non-dry_run call. Required to actually apply."),
      },
    },
    async (args) => toToolResult("backup/restore", await client.post("/backup/restore", args)),
  );

  server.registerTool(
    "iawm_backup_delete",
    {
      title: "Delete a backup",
      description:
        "Permanently removes a backup record. The payload cannot be recovered after deletion.",
      inputSchema: {
        id: z.number().int().describe("Backup id"),
      },
    },
    async (args) => toToolResult("backup/delete", await client.post("/backup/delete", args)),
  );

  server.registerTool(
    "iawm_backup_prune",
    {
      title: "Prune old backups",
      description:
        "Storage hygiene: keeps only the last `keep` backup records (newest first) and deletes the rest. Defaults to keeping 50.",
      inputSchema: {
        keep: z.number().int().optional().describe("Number of records to keep (default 50)"),
      },
    },
    async (args) => toToolResult("backup/prune", await client.post("/backup/prune", args)),
  );
}

export function registerTools(server: McpServer, client: IawmClient): void {
  registerSystem(server, client);
  registerContent(server, client);
  registerMedia(server, client);
  registerTaxonomy(server, client);
  registerMenu(server, client);
  registerDiagnostics(server, client);
  registerConfig(server, client);
  registerPlugins(server, client);
  registerThemes(server, client);
  registerCore(server, client);
  registerDatabase(server, client);
  registerCron(server, client);
  registerSeo(server, client);
  registerDivi(server, client);
  registerBackup(server, client);
}
