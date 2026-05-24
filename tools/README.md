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
