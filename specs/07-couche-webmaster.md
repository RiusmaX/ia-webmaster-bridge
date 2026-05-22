# Spec 07 — Couche webmaster (skills & workflows)

- **Statut** : Ébauche
- **Phase** : 6
- **Priorité** : Moyenne
- **Dernière mise à jour** : 2026-05-21

## Objectif

Transformer un ensemble de capacités techniques en un véritable **savoir-faire
de webmaster** : donner à Claude des méthodes de travail, du contexte par site,
et des workflows prêts à l'emploi.

## Périmètre

### Inclus
- **Skills Claude Code** : workflows réutilisables (créer une landing page,
  audit SEO, mise à jour sécurisée des extensions, etc.).
- **Fichier de contexte par site** : décrit le site (charte, conventions,
  arborescence, extensions clés, particularités Divi).
- Procédures de vérification (relire son travail, smoke test après opération).

### Exclu (pour l'instant)
- Les capacités techniques elles-mêmes → specs 03 à 06.
- Les routines planifiées (éventuelles, à évaluer une fois la base stable).

## Approche technique

- Les capacités (specs 03–06) sont des **briques de bas niveau**. Cette couche
  les assemble en **procédures de haut niveau** que Claude suit comme le ferait
  un webmaster expérimenté.
- **Skills** : chaque workflow récurrent devient un skill documenté — étapes,
  garde-fous, critères de réussite. Dossier `skills/`.
- **Contexte par site** : chaque cible a un fichier de contexte (charte
  graphique, ton éditorial, structure des pages, conventions de nommage,
  extensions installées). Claude le lit avant d'agir sur ce site.
- **Boucle de vérification** : tout workflow se termine par une relecture du
  résultat (l'agent vérifie son propre travail) et, si pertinent, un smoke test.

## Workflows pressentis (à préciser)

- Créer une landing page Divi 5 à partir d'un brief.
- Auditer le SEO d'un ensemble de pages et proposer des correctifs.
- Mettre à jour les extensions en sécurité (sauvegarde → mise à jour → smoke
  test → rapport).
- Détecter et corriger les liens cassés.
- Produire un rapport d'état du site (contenu, performances, à-faire).

## Points ouverts

- Format du fichier de contexte par site (un Markdown ? un skill dédié ?).
- Quels workflows prioriser une fois les Phases 1–5 stables ?
- Faut-il des routines planifiées (audit hebdomadaire, etc.) ? À évaluer.
- Comment capitaliser : un workflow réussi sur un site doit-il enrichir un
  patrimoine de méthodes commun à tous les sites ?

## Dépendances & risques

- Dépend de toutes les autres specs : c'est la couche d'assemblage finale.
- Risque : sur-industrialiser trop tôt. Cette couche ne prend son sens qu'une
  fois les capacités de base fiables — d'où sa position en Phase 6.
