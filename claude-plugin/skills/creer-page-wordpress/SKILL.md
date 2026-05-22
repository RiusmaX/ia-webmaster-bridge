---
name: creer-page-wordpress
description: Créer une page ou un article WordPress de bout en bout — du brief au contenu prêt à publier, en blocs Gutenberg, avec création en brouillon et relecture.
---

# Créer une page ou un article WordPress

Workflow pour créer proprement un nouveau contenu via l'adaptateur IA Webmaster.

## 1. Clarifier le brief

Connaître : le type (`page` ou `post`), le titre, l'objectif du contenu, la
structure souhaitée, et si une publication immédiate est demandée (sinon :
brouillon).

## 2. Prendre le contexte

- `iawm_content_list` pour voir les contenus existants — éviter les doublons,
  s'aligner sur le style du site.
- Pour une page enfant : repérer l'`id` du parent.

## 3. Rédiger le contenu en blocs Gutenberg

Composer le corps en balisage de blocs Gutenberg, par exemple :

    <!-- wp:heading --><h2>Titre de section</h2><!-- /wp:heading -->
    <!-- wp:paragraph --><p>Texte du paragraphe.</p><!-- /wp:paragraph -->

L'adaptateur normalise automatiquement ce balisage : il n'a pas à être parfait.
Blocs courants : `heading`, `paragraph`, `list`, `image`, `quote`, `buttons`,
`columns`.

## 4. Prévisualiser puis créer

1. `iawm_content_create` avec `dry_run: true` — vérifier ce qui serait créé.
2. Création réelle : `iawm_content_create` sans dry_run. Le contenu est créé en
   **brouillon**.
3. `iawm_content_get` pour relire et confirmer.

## 5. Médias éventuels

Pour ajouter une image : `iawm_media_sideload` (depuis une URL), en renseignant
toujours le **texte alternatif** (`alt`), puis référencer le média dans le
contenu.

## 6. Publication

Publier uniquement si l'utilisateur l'a demandé : `iawm_content_update` avec
`status: publish`. Sinon, laisser en brouillon et communiquer le lien d'aperçu.
