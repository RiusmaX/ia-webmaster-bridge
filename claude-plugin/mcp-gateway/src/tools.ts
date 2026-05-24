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
        "Installs a plugin from the WordPress.org repository. With activate=true, activates it right after. Returns the path to the installed plugin file.",
      inputSchema: {
        slug: z.string().describe("WordPress.org slug of the plugin to install"),
        activate: z.boolean().optional().describe("Activate immediately after install (default false)"),
      },
    },
    async (args) => toToolResult("plugins/install", await client.post("/plugins/install", args)),
  );

  server.registerTool(
    "iawm_plugins_activate",
    {
      title: "Activate a plugin",
      description:
        "Activates an already-installed plugin. `file` is the path returned by diagnostics/plugins (e.g. rank-math-seo/rank-math.php).",
      inputSchema: {
        file: z.string().describe("Plugin file (e.g. rank-math-seo/rank-math.php)"),
      },
    },
    async (args) => toToolResult("plugins/activate", await client.post("/plugins/activate", args)),
  );

  server.registerTool(
    "iawm_plugins_deactivate",
    {
      title: "Deactivate a plugin",
      description:
        "Deactivates a plugin. The IA Webmaster Bridge plugin itself cannot be deactivated via the API (guardrail).",
      inputSchema: {
        file: z.string().describe("Plugin file to deactivate"),
      },
    },
    async (args) => toToolResult("plugins/deactivate", await client.post("/plugins/deactivate", args)),
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
        "Fetches the site's Divi design system: global colors (gcid-*), global variables (CSS variables), global fonts. Call this BEFORE generating a layout to reference global colors/fonts.",
    },
    async () => toToolResult("divi/global-data", await client.post("/divi/global-data", {})),
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
export function registerTools(server: McpServer, client: IawmClient): void {
  registerSystem(server, client);
  registerContent(server, client);
  registerMedia(server, client);
  registerTaxonomy(server, client);
  registerMenu(server, client);
  registerDiagnostics(server, client);
  registerConfig(server, client);
  registerPlugins(server, client);
  registerSeo(server, client);
  registerDivi(server, client);
}
