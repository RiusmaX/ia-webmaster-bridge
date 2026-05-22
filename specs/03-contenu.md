# Spec 03 — Plan contenu

- **Statut** : Ébauche
- **Phase** : 2
- **Priorité** : Haute
- **Dernière mise à jour** : 2026-05-21

## Objectif

Permettre à Claude de gérer le contenu éditorial du site : pages, articles,
médias, menus, taxonomies, et le contenu en blocs Gutenberg.

## Périmètre

### Inclus
- CRUD des pages et articles (et types de contenu custom exposés).
- Gestion de la médiathèque (upload, métadonnées, texte alternatif).
- Menus de navigation.
- Taxonomies (catégories, étiquettes, taxonomies custom).
- Génération de contenu en **blocs Gutenberg** valides.
- Opérations en masse (ex. mise à jour groupée).

### Exclu (pour l'instant)
- La génération de layouts Divi 5 → spec `04-divi5.md`.
- Les réglages globaux du site → spec `05-configuration.md`.

## Approche technique

- Capacités exposées par le plugin sous `ia-webmaster/v1`, regroupées
  « contenu ». Sauf besoin custom, elles s'appuient sur les fonctions WordPress
  natives et l'API REST `wp/v2` côté serveur.
- **Blocs Gutenberg** : le contenu d'une page est du balisage de blocs dans
  `post_content`. Ne jamais l'écrire à la main comme une chaîne : construire un
  arbre de blocs et le sérialiser via `serialize_blocks()` (PHP) côté plugin. Un
  balisage invalide déclenche la « récupération de bloc » dans l'éditeur.
- **Médias** : upload via une capacité dédiée ; renseigner systématiquement le
  texte alternatif et le titre (qualité + accessibilité + SEO).
- **Menus** : gérer les menus classiques et la navigation par blocs
  (`wp_navigation`) selon le thème.
- **Garde-fous** (spec 02) : création en brouillon par défaut, dry-run
  disponible, publication explicite.

## Points ouverts

- Une page sous Divi 5 ne se gère pas comme une page en blocs Gutenberg : la
  frontière entre cette spec et la spec 04 doit être nette (détection du mode de
  construction de la page avant toute écriture).
- Faut-il une couche d'abstraction « contenu de haut niveau » (titres,
  paragraphes, images) que l'agent manipule, traduite ensuite en blocs ?
- Gestion des révisions : exposer l'historique et la restauration ?
- Types de contenu custom : découverte automatique ou déclaration explicite ?

## Dépendances & risques

- Dépend des specs 01 (adaptateur) et 02 (sécurité).
- Risque : écrire du contenu Gutenberg dans une page construite avec Divi (ou
  l'inverse) corromprait la page → la détection du mode de page est critique.
