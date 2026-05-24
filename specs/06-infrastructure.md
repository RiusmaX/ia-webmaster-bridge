# Spec 06 — Plan infrastructure

- **Statut** : Ébauche
- **Phase** : 4
- **Priorité** : Moyenne
- **Dernière mise à jour** : 2026-05-21

## Objectif

Permettre à Claude de conduire les opérations d'infrastructure du site :
extensions, thèmes, base de données, sauvegardes, tâches planifiées,
mises à jour.

## Périmètre

### Inclus
- Extensions : installation, activation/désactivation, mise à jour.
- Thèmes : installation, activation, mise à jour.
- Base de données : export, requêtes contrôlées, `search-replace`.
- Sauvegardes et restauration.
- Tâches planifiées (cron WordPress).
- Mise à jour du cœur WordPress.

### Exclu (pour l'instant)
- L'administration système hors WordPress (configuration serveur, DNS, etc.).
- La modification de `wp-config.php` au-delà de réglages explicitement exposés.

## Approche technique

- Conformément à la décision **D-006**, ces opérations passent par des
  **endpoints contrôlés du plugin**, pas par un shell ouvert à l'agent.
- Le plugin, tournant en PHP avec les droits de WordPress, peut réaliser la
  plupart de ces opérations (installation d'extensions, options, cron). Pour ce
  qui dépasse PHP, le plugin pourra encapsuler des appels WP-CLI précis et
  validés (jamais une commande arbitraire).
- **Canal SSH/WP-CLI de secours** : réservé à l'opérateur humain, ou aux
  situations où le plugin est indisponible (ex. plugin lui-même cassé).
  Documenté, non exposé à l'agent par défaut.
- **Toutes ces opérations sont classées « à risque »** : sauvegarde préalable,
  confirmation explicite, dry-run quand c'est possible (spec 02).
- Sources d'extensions/thèmes : restreindre aux sources de confiance (dépôt
  officiel, archives validées) — pas d'installation depuis une URL arbitraire.

## Points ouverts

- Mécanisme de sauvegarde : s'appuyer sur une extension de sauvegarde existante,
  ou implémenter une sauvegarde minimale dans le plugin ?
- Quelles commandes WP-CLI encapsuler, et lesquelles laisser strictement à
  l'opérateur humain ?
- `search-replace` est puissant et dangereux (sérialisation) → garde-fous
  spécifiques, dry-run obligatoire.
- Mises à jour : faut-il un environnement de pré-production pour tester une mise
  à jour avant la prod ?
- Comment l'agent vérifie-t-il qu'un site est sain après une opération
  (smoke test) ?

## Dépendances & risques

- Dépend des specs 01 (adaptateur) et 02 (sécurité).
- **Risque élevé** : ce plan contient les opérations les plus destructrices du
  projet (mises à jour, base de données). Garde-fous non négociables ;
  déploiement sur prod seulement après forte stabilité sur le local.
- Risque : une mise à jour casse le site → sauvegarde préalable + procédure de
  restauration testée avant tout usage en prod.
