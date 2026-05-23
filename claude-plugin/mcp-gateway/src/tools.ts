/**
 * Déclaration des outils MCP exposés par le pont.
 *
 * Chaque outil correspond à une route de l'adaptateur. Les outils GET
 * (diagnostic système) n'ont pas de corps ; tous les autres envoient leurs
 * paramètres dans le corps JSON de la requête signée.
 *
 * Organisation par domaine : système, contenu, médias, taxonomies, menus,
 * diagnostic, configuration.
 */

import { z } from "zod";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { IawmClient, type ApiResult } from "./client.js";

/** Met en forme un résultat d'API en réponse d'outil MCP. */
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

/** Statuts d'écriture acceptés (doivent correspondre au plugin). */
const WRITE_STATUS = z.enum(["draft", "publish", "pending", "private", "future"]);

/* ------------------------------------------------------------------ */
/* Système (diagnostic de connexion)                                  */
/* ------------------------------------------------------------------ */

function registerSystem(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_ping",
    {
      title: "Diagnostic public",
      description:
        "Vérifie que l'adaptateur est joignable et renvoie les versions de l'environnement (WordPress, PHP, Divi). Aucune authentification requise.",
    },
    async () => toToolResult("ping", await client.get("/ping")),
  );

  server.registerTool(
    "iawm_status",
    {
      title: "État authentifié",
      description:
        "Diagnostic authentifié : valide la connexion signée HMAC et renvoie l'état de l'adaptateur (identité de la clé, kill switch, environnement).",
    },
    async () => toToolResult("status", await client.get("/status")),
  );

  server.registerTool(
    "iawm_audit",
    {
      title: "Journal d'audit",
      description:
        "Renvoie les dernières entrées du journal d'audit : chaque appel de l'API y est tracé (date, route, résultat, identité, IP).",
    },
    async () => toToolResult("audit", await client.get("/audit")),
  );
}

/* ------------------------------------------------------------------ */
/* Contenu (pages et articles)                                        */
/* ------------------------------------------------------------------ */

function registerContent(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_content_list",
    {
      title: "Lister le contenu",
      description: "Liste les pages ou articles du site, avec pagination, recherche et filtre par statut.",
      inputSchema: {
        type: z.enum(["post", "page"]).optional().describe("Type de contenu (défaut : post)"),
        status: z.string().optional().describe("Statuts inclus, séparés par des virgules (ex. publish,draft)"),
        search: z.string().optional().describe("Terme de recherche"),
        per_page: z.number().int().min(1).max(100).optional().describe("Résultats par page (défaut : 20)"),
        page: z.number().int().min(1).optional().describe("Numéro de page (défaut : 1)"),
      },
    },
    async (args) => toToolResult("content/list", await client.post("/content/list", args)),
  );

  server.registerTool(
    "iawm_content_get",
    {
      title: "Lire un contenu",
      description: "Renvoie le contenu détaillé d'une page ou d'un article : corps complet, métadonnées, builder détecté.",
      inputSchema: {
        id: z.number().int().describe("Identifiant du contenu"),
      },
    },
    async (args) => toToolResult("content/get", await client.post("/content/get", args)),
  );

  server.registerTool(
    "iawm_content_create",
    {
      title: "Créer un contenu",
      description:
        "Crée une page ou un article. Statut « draft » par défaut (publication explicite). Le balisage de blocs Gutenberg est normalisé sauf si raw_content=true. dry_run=true simule sans rien créer.",
      inputSchema: {
        type: z.enum(["post", "page"]).describe("Type de contenu à créer"),
        title: z.string().optional().describe("Titre"),
        content: z.string().optional().describe("Contenu (HTML ou balisage de blocs Gutenberg)"),
        status: WRITE_STATUS.optional().describe("Statut (défaut : draft)"),
        slug: z.string().optional(),
        excerpt: z.string().optional(),
        parent: z.number().int().optional().describe("ID du contenu parent"),
        menu_order: z.number().int().optional(),
        template: z.string().optional().describe("Slug de gabarit de page"),
        raw_content: z.boolean().optional().describe("True pour ne pas normaliser le contenu"),
        dry_run: z.boolean().optional().describe("True pour simuler sans rien créer"),
      },
    },
    async (args) => toToolResult("content/create", await client.post("/content/create", args)),
  );

  server.registerTool(
    "iawm_content_update",
    {
      title: "Modifier un contenu",
      description:
        "Modifie une page ou un article existant. Seuls les champs fournis sont changés. dry_run=true prévisualise sans appliquer.",
      inputSchema: {
        id: z.number().int().describe("Identifiant du contenu à modifier"),
        title: z.string().optional(),
        content: z.string().optional().describe("Nouveau contenu (HTML ou blocs Gutenberg)"),
        status: WRITE_STATUS.optional(),
        slug: z.string().optional(),
        excerpt: z.string().optional(),
        parent: z.number().int().optional(),
        menu_order: z.number().int().optional(),
        template: z.string().optional(),
        raw_content: z.boolean().optional().describe("True pour ne pas normaliser le contenu"),
        dry_run: z.boolean().optional().describe("True pour prévisualiser sans appliquer"),
      },
    },
    async (args) => toToolResult("content/update", await client.post("/content/update", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Médias                                                             */
/* ------------------------------------------------------------------ */

function registerMedia(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_media_list",
    {
      title: "Lister les médias",
      description: "Liste la médiathèque, avec pagination, recherche et filtre par type MIME.",
      inputSchema: {
        search: z.string().optional().describe("Terme de recherche"),
        mime_type: z.string().optional().describe("Filtre par type MIME (ex. image)"),
        per_page: z.number().int().min(1).max(100).optional(),
        page: z.number().int().min(1).optional(),
      },
    },
    async (args) => toToolResult("media/list", await client.post("/media/list", args)),
  );

  server.registerTool(
    "iawm_media_get",
    {
      title: "Lire un média",
      description: "Renvoie le détail d'un média : URL, type MIME, texte alternatif, dimensions.",
      inputSchema: {
        id: z.number().int().describe("Identifiant du média"),
      },
    },
    async (args) => toToolResult("media/get", await client.post("/media/get", args)),
  );

  server.registerTool(
    "iawm_media_sideload",
    {
      title: "Importer un média depuis une URL",
      description:
        "Télécharge un fichier depuis une URL et l'ajoute à la médiathèque. dry_run=true simule sans rien importer.",
      inputSchema: {
        url: z.string().describe("URL du fichier à importer"),
        title: z.string().optional(),
        alt: z.string().optional().describe("Texte alternatif"),
        caption: z.string().optional().describe("Légende"),
        description: z.string().optional(),
        attached_to: z.number().int().optional().describe("ID du contenu auquel rattacher le média"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("media/sideload", await client.post("/media/sideload", args)),
  );

  server.registerTool(
    "iawm_media_update",
    {
      title: "Modifier un média",
      description: "Met à jour les métadonnées d'un média (titre, texte alternatif, légende, description).",
      inputSchema: {
        id: z.number().int().describe("Identifiant du média"),
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
      title: "Lister les termes",
      description: "Liste les termes d'une taxonomie (category, post_tag ou taxonomie personnalisée).",
      inputSchema: {
        taxonomy: z.string().describe("Slug de la taxonomie (ex. category, post_tag)"),
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
      title: "Créer un terme",
      description: "Crée un terme (catégorie, étiquette…) dans une taxonomie.",
      inputSchema: {
        taxonomy: z.string().describe("Slug de la taxonomie"),
        name: z.string().describe("Nom du terme"),
        slug: z.string().optional(),
        description: z.string().optional(),
        parent: z.number().int().optional().describe("ID du terme parent"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("taxonomy/create", await client.post("/taxonomy/create", args)),
  );

  server.registerTool(
    "iawm_taxonomy_assign",
    {
      title: "Assigner des termes",
      description:
        "Assigne des termes à un contenu. terms accepte des identifiants (recommandé) ou des noms. append=true ajoute sans remplacer.",
      inputSchema: {
        id: z.number().int().describe("Identifiant du contenu"),
        taxonomy: z.string().describe("Slug de la taxonomie"),
        terms: z.array(z.union([z.string(), z.number()])).describe("Termes (identifiants ou noms)"),
        append: z.boolean().optional().describe("True pour ajouter aux termes existants"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("taxonomy/assign", await client.post("/taxonomy/assign", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Menus de navigation                                                 */
/* ------------------------------------------------------------------ */

function registerMenu(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_menu_list",
    {
      title: "Lister les menus",
      description: "Liste les menus de navigation et les emplacements de menu du thème.",
    },
    async () => toToolResult("menu/list", await client.post("/menu/list", {})),
  );

  server.registerTool(
    "iawm_menu_get",
    {
      title: "Lire un menu",
      description: "Renvoie un menu et ses éléments.",
      inputSchema: {
        id: z.number().int().describe("Identifiant du menu"),
      },
    },
    async (args) => toToolResult("menu/get", await client.post("/menu/get", args)),
  );

  server.registerTool(
    "iawm_menu_create",
    {
      title: "Créer un menu",
      description: "Crée un menu de navigation.",
      inputSchema: {
        name: z.string().describe("Nom du menu"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("menu/create", await client.post("/menu/create", args)),
  );

  server.registerTool(
    "iawm_menu_add_item",
    {
      title: "Ajouter un élément de menu",
      description:
        "Ajoute un élément à un menu. Fournir url pour un lien personnalisé, ou object_id pour pointer vers une page ou un article.",
      inputSchema: {
        menu_id: z.number().int().describe("Identifiant du menu"),
        title: z.string().optional().describe("Libellé de l'élément"),
        url: z.string().optional().describe("URL (pour un lien personnalisé)"),
        object_id: z.number().int().optional().describe("ID d'une page/article (pour un lien interne)"),
        parent_item: z.number().int().optional().describe("ID de l'élément parent"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("menu/add-item", await client.post("/menu/add-item", args)),
  );

  server.registerTool(
    "iawm_menu_remove_item",
    {
      title: "Retirer un élément de menu",
      description: "Supprime un élément de menu.",
      inputSchema: {
        item_id: z.number().int().describe("Identifiant de l'élément de menu"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("menu/remove-item", await client.post("/menu/remove-item", args)),
  );

  server.registerTool(
    "iawm_menu_assign_location",
    {
      title: "Assigner un menu à un emplacement",
      description: "Assigne un menu à un emplacement de menu du thème.",
      inputSchema: {
        menu_id: z.number().int().describe("Identifiant du menu"),
        location: z.string().describe("Slug de l'emplacement du thème"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("menu/assign-location", await client.post("/menu/assign-location", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Diagnostic (lecture seule)                                          */
/* ------------------------------------------------------------------ */

function registerDiagnostics(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_diagnostics_system",
    {
      title: "Diagnostic système",
      description: "Versions (WordPress, PHP, MySQL), thème actif, état du débogage et limites PHP.",
    },
    async () => toToolResult("diagnostics/system", await client.post("/diagnostics/system", {})),
  );

  server.registerTool(
    "iawm_diagnostics_plugins",
    {
      title: "État des extensions",
      description: "Liste les extensions installées : version, actif/inactif, mise à jour disponible.",
    },
    async () => toToolResult("diagnostics/plugins", await client.post("/diagnostics/plugins", {})),
  );

  server.registerTool(
    "iawm_diagnostics_themes",
    {
      title: "État des thèmes",
      description: "Liste les thèmes installés et indique celui qui est actif.",
    },
    async () => toToolResult("diagnostics/themes", await client.post("/diagnostics/themes", {})),
  );

  server.registerTool(
    "iawm_diagnostics_logs",
    {
      title: "Lire le journal de débogage",
      description: "Renvoie les dernières lignes du debug.log de WordPress (s'il existe).",
      inputSchema: {
        lines: z.number().int().min(1).max(1000).optional().describe("Nombre de lignes (défaut : 100)"),
      },
    },
    async (args) => toToolResult("diagnostics/logs", await client.post("/diagnostics/logs", args)),
  );
}

/* ------------------------------------------------------------------ */
/* Configuration (réglages et utilisateurs)                            */
/* ------------------------------------------------------------------ */

function registerConfig(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_config_settings_get",
    {
      title: "Lire les réglages",
      description: "Renvoie les réglages du site modifiables (titre, slogan, fuseau, lecture, permaliens…).",
    },
    async () => toToolResult("config/settings/get", await client.post("/config/settings/get", {})),
  );

  server.registerTool(
    "iawm_config_settings_update",
    {
      title: "Modifier les réglages",
      description:
        "Modifie des réglages du site. Seules les options d'une liste blanche sont acceptées ; les autres sont rejetées. dry_run=true prévisualise.",
      inputSchema: {
        settings: z.record(z.string(), z.unknown()).describe("Objet { clé: valeur } des réglages à modifier"),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("config/settings/update", await client.post("/config/settings/update", args)),
  );

  server.registerTool(
    "iawm_config_users_list",
    {
      title: "Lister les utilisateurs",
      description: "Liste les comptes utilisateurs (identifiant, e-mail, rôles).",
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
      title: "Créer un utilisateur",
      description:
        "Crée un compte utilisateur. Rôle « subscriber » par défaut. Sans mot de passe fourni, un mot de passe fort est généré et renvoyé.",
      inputSchema: {
        login: z.string().describe("Identifiant de connexion"),
        email: z.string().describe("Adresse e-mail"),
        password: z.string().optional().describe("Mot de passe (généré si absent)"),
        role: z
          .enum(["subscriber", "contributor", "author", "editor", "administrator"])
          .optional()
          .describe("Rôle (défaut : subscriber)"),
        display_name: z.string().optional(),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("config/users/create", await client.post("/config/users/create", args)),
  );

  server.registerTool(
    "iawm_config_users_update",
    {
      title: "Modifier un utilisateur",
      description:
        "Modifie un utilisateur (e-mail, nom affiché, rôle). L'utilisateur sous lequel l'agent opère ne peut pas être modifié.",
      inputSchema: {
        id: z.number().int().describe("Identifiant de l'utilisateur"),
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
/* Plugins (infrastructure — installation/activation)                 */
/* ------------------------------------------------------------------ */

function registerPlugins(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_plugins_info",
    {
      title: "Informations sur un plugin WP.org",
      description:
        "Récupère les métadonnées d'un plugin du dépôt WordPress.org à partir de son slug (version, auteur, compatibilité, dernière mise à jour).",
      inputSchema: {
        slug: z.string().describe("Slug WordPress.org (ex. rank-math-seo)"),
      },
    },
    async (args) => toToolResult("plugins/info", await client.post("/plugins/info", args)),
  );

  server.registerTool(
    "iawm_plugins_install",
    {
      title: "Installer un plugin",
      description:
        "Installe un plugin depuis le dépôt WordPress.org. Avec activate=true, l'active dans la foulée. Renvoie le chemin du fichier-plugin installé.",
      inputSchema: {
        slug: z.string().describe("Slug WordPress.org du plugin à installer"),
        activate: z.boolean().optional().describe("Activer immédiatement après installation (défaut false)"),
      },
    },
    async (args) => toToolResult("plugins/install", await client.post("/plugins/install", args)),
  );

  server.registerTool(
    "iawm_plugins_activate",
    {
      title: "Activer un plugin",
      description:
        "Active un plugin déjà installé. Le file est le chemin renvoyé par diagnostics/plugins (ex. rank-math-seo/rank-math.php).",
      inputSchema: {
        file: z.string().describe("Fichier-plugin (ex. rank-math-seo/rank-math.php)"),
      },
    },
    async (args) => toToolResult("plugins/activate", await client.post("/plugins/activate", args)),
  );

  server.registerTool(
    "iawm_plugins_deactivate",
    {
      title: "Désactiver un plugin",
      description:
        "Désactive un plugin. Le plugin IA Webmaster Bridge ne peut pas être désactivé via l'API (garde-fou).",
      inputSchema: {
        file: z.string().describe("Fichier-plugin à désactiver"),
      },
    },
    async (args) => toToolResult("plugins/deactivate", await client.post("/plugins/deactivate", args)),
  );
}

/* ------------------------------------------------------------------ */
/* SEO (Rank Math / Yoast)                                            */
/* ------------------------------------------------------------------ */

function registerSeo(server: McpServer, client: IawmClient): void {
  server.registerTool(
    "iawm_seo_status",
    {
      title: "État du backend SEO",
      description:
        "Indique quel plugin SEO est actif sur le site (Rank Math prioritaire, Yoast secondaire) et la liste des champs supportés par l'API.",
    },
    async () => toToolResult("seo/status", await client.post("/seo/status", {})),
  );

  server.registerTool(
    "iawm_seo_page_get",
    {
      title: "Lire le SEO d'une page",
      description:
        "Renvoie les méta-données SEO d'un post : meta_title, meta_description, focus_keyword, canonical_url, robots, Open Graph, Twitter.",
      inputSchema: {
        post_id: z.number().int().describe("Identifiant du post/page"),
      },
    },
    async (args) => toToolResult("seo/page/get", await client.post("/seo/page/get", args)),
  );

  server.registerTool(
    "iawm_seo_page_update",
    {
      title: "Modifier le SEO d'une page",
      description:
        "Met à jour les méta-données SEO d'un post. Les noms de champs sont normalisés (indépendants du backend) : meta_title, meta_description, focus_keyword, canonical_url, robots_noindex, robots_nofollow, og_title, og_description, og_image_id, twitter_title, twitter_description, twitter_image_id. dry_run=true prévisualise.",
      inputSchema: {
        post_id: z.number().int().describe("Identifiant du post/page"),
        fields: z
          .record(z.string(), z.unknown())
          .describe("Champs à mettre à jour. Mettre null/\"\" pour supprimer un champ."),
        dry_run: z.boolean().optional(),
      },
    },
    async (args) => toToolResult("seo/page/update", await client.post("/seo/page/update", args)),
  );
}

/**
 * Enregistre tous les outils du pont sur le serveur MCP.
 *
 * @param server Serveur MCP.
 * @param client Client signé vers l'adaptateur.
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
}
