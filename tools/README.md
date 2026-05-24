# `tools/`

Outils de développement et de diagnostic du projet.

> **Note** : ce dossier était historiquement peuplé de scripts spécifiques
> à l'environnement local du mainteneur (paths `C:/Users/...`, bootstrap
> WordPress en CLI sous LocalWP). Ils ont été retirés du dépôt pour rester
> agnostique. L'historique git en conserve la trace si besoin de référence.
>
> Pour interagir avec votre site WordPress local pendant le développement,
> utilisez plutôt :
>
> - Les outils MCP `iawm_*` une fois le plugin et le pont installés.
> - WP-CLI directement (`wp` dans le shell du site).
> - L'API REST signée via un client HTTP de votre choix (le format de
>   signature est documenté dans `plugin/ia-webmaster-bridge/includes/class-iawm-auth.php`).
