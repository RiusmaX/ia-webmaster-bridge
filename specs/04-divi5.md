# Spec 04 — Plan Divi 5 (prioritaire)

- **Statut** : Ébauche
- **Phase** : 3
- **Priorité** : Prioritaire
- **Dernière mise à jour** : 2026-05-21

## Objectif

Permettre à Claude de **lire, créer et modifier des layouts Divi 5** : le cœur
de la valeur du projet, puisque la majorité des sites cibles sont sous
Divi 5 (décision D-003).

## Contexte

Divi 5 (sorti le 26 février 2026) a réécrit son architecture : builder React,
contenu stocké en **format JSON / blocs sérialisés** (proche du format
Gutenberg), attributs hiérarchiques multi-breakpoints et multi-états. Les
layouts Divi 4 sont migrés vers ce format.

**Difficulté assumée** : il n'existe **pas d'API publique documentée** pour
générer un layout Divi 5 complet par programme. Les ressources disponibles :
- le format `.json` d'import/export « portability » (officiel, mais contraint
  par le contexte d'export) ;
- la nouvelle Builder API Divi 5 (orientée modules custom — périmètre à
  explorer) ;
- une documentation communautaire non officielle (à vérifier, jamais à supposer
  exacte).

**Découverte sur le site local (2026-05-21)** : Divi 5 expose en réalité un
namespace REST complet `divi/v1` (~110 routes), dont `portability/import` et
`portability/export` (layouts JSON), `outside-vb/posts/set-layout` (appliquer un
layout à une page hors Visual Builder), `divi-library/*`, `outside-vb/theme-builder/*`
et `page-manager/*`. Ces routes sont conçues pour le Visual Builder ; leur usage
en server-to-server (authentification, nonces) reste à qualifier en Phase 3, mais
elles constituent une voie programmatique sérieuse — à privilégier sur la
rétro-ingénierie pure.

Conséquence : la première brique de cette phase est une **rétro-ingénierie du
format réel** sur le site local, en s'appuyant sur ce que `divi/v1` permet
déjà.

## Périmètre

### Inclus
- Lecture d'un layout Divi 5 existant (structure, modules, réglages).
- Génération de layouts Divi 5 par programme.
- Modification de layouts existants.
- Bibliothèque de génération côté Claude (`lib/divi/`).

### Exclu (pour l'instant)
- Divi 4 (sites encore en Divi 4 — à traiter plus tard si besoin).
- Elementor (reporté, décision D-003).
- La création de modules Divi custom (Builder API) — hors périmètre initial.

## Approche technique (itérative)

1. **Observer le format réel.** Sur le site local : créer dans le builder Divi 5
   plusieurs layouts de référence (section simple, colonnes, modules courants),
   puis extraire et analyser leur stockage exact (`post_content` et/ou post
   meta). C'est la source de vérité — pas la doc communautaire.
2. **Lire avant d'écrire.** Une capacité de lecture qui restitue un layout Divi 5
   dans une structure exploitable par Claude.
3. **Aller-retour (round-trip).** Vérifier qu'un layout lu puis réécrit tel quel
   reste identique et s'ouvre sans erreur dans le builder. Critère de fiabilité.
4. **Génération incrémentale.** Construire `lib/divi/` : des aides qui produisent
   un JSON Divi 5 valide à partir d'une intention de haut niveau (section →
   lignes → colonnes → modules). Commencer par un sous-ensemble réduit de
   modules, élargir au fur et à mesure.
5. **Import contrôlé.** Capacité plugin qui applique un layout généré à une page,
   en respectant les contraintes de contexte du format « portability ».

## Points ouverts

- Où Divi 5 stocke-t-il exactement le layout (post_content, post meta, table
  dédiée) ? → à confirmer par l'observation (étape 1).
- Faut-il, après écriture, déclencher une régénération de cache / d'assets Divi
  (analogue au cache CSS d'Elementor) ? → à vérifier.
- Le format « portability » est-il suffisant, ou faut-il écrire le stockage
  natif directement ?
- La Builder API Divi 5 offre-t-elle un point d'entrée pour la génération de
  layouts, ou seulement pour les modules custom ?
- Modèle des attributs multi-breakpoints / multi-états : structure exacte à
  cartographier.
- Stratégie pour les modules tiers (extensions Divi) présents sur les sites.

## Dépendances & risques

- Dépend des specs 01 (adaptateur) et 02 (sécurité).
- **Risque principal** : format non documenté et susceptible d'évoluer entre
  versions mineures de Divi 5 → d'où l'observation directe, l'épinglage de la
  version de Divi testée, et le test d'aller-retour comme garde-fou.
- Risque : un layout généré invalide peut casser l'affichage d'une page → tout
  passe par le brouillon et le dry-run (spec 02) avant publication.
