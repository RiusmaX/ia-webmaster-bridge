# `tools/`

Development and diagnostic tools for the project.

> **Note**: this folder used to host scripts specific to the maintainer's
> local environment (paths `C:/Users/...`, WordPress CLI bootstrap under
> LocalWP). They have been removed from the repository to keep it
> agnostic. Git history preserves them for reference if needed.
>
> To interact with your local WordPress site during development, use
> instead:
>
> - The `iawm_*` MCP tools, once the plugin and gateway are installed.
> - WP-CLI directly (`wp` in the site shell).
> - The signed REST API via the HTTP client of your choice (the
>   signature format is documented in
>   `plugin/ia-webmaster-bridge/includes/class-iawm-auth.php`).

## Scripts

| Script | Purpose |
|---|---|
| `scan-divi-modules.mjs` | Discovers Divi 5 module presets and updates the modules catalog. |
| `extract-pot.mjs` | Walks the plugin PHP source and writes `plugin/ia-webmaster-bridge/languages/ia-webmaster-bridge.pot`. Recognises `__`, `_e`, `esc_html__`, `esc_html_e`, `esc_attr__`, `esc_attr_e` and `_n` calls whose text domain is `ia-webmaster-bridge`. |
| `compile-mo.mjs` | Compiles a `.po` file into the binary `.mo` format that WordPress actually loads at runtime. Pure Node — no dependency on `msgfmt` or wp-cli. Usage: `node tools/compile-mo.mjs path/to/file.po`. |
