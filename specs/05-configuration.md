# Spec 05 — Plan configuration

- **Statut** : Ébauche
- **Phase** : 2
- **Priorité** : Moyenne
- **Dernière mise à jour** : 2026-05-21

## Objectif

Permettre à Claude de gérer la configuration du site : réglages généraux,
options du thème, utilisateurs et rôles.

## Périmètre

### Inclus
- Réglages généraux WordPress (titre, slogan, fuseau, permaliens, langue…).
- Options et personnalisation du thème actif.
- Gestion des utilisateurs (création, rôles, profils).
- Réglages de discussion, lecture, médias.

### Exclu (pour l'instant)
- L'installation / activation d'extensions et de thèmes → spec `06-infrastructure.md`.
- Les réglages spécifiques à Divi → couverts avec la spec `04-divi5.md`.

## Approche technique

- Capacités exposées par le plugin, regroupées « configuration ».
- **Lecture exhaustive d'abord** : une capacité qui dresse l'état de
  configuration du site, pour que Claude décide en connaissance de cause.
- Écritures **ciblées et explicites** : pas de modification globale en bloc ;
  chaque réglage modifié est nommé, et le dry-run montre l'avant/après.
- **Utilisateurs** : capacité sensible. Création/modification d'utilisateurs
  classée « à risque » → confirmation explicite (spec 02). Interdiction de
  modifier ou supprimer l'utilisateur dédié à l'agent lui-même.
- Certains réglages (permaliens, langue) ont des effets de bord importants → les
  classer « à risque » et sauvegarder avant.

## Points ouverts

- Périmètre exact des « options du thème » exposées (variable selon le thème).
- Faut-il une capacité de sauvegarde/restauration d'un jeu de réglages
  (snapshot de configuration) ?
- Gestion des réglages multisites si le cas se présente.
- Quels réglages interdire purement et simplement à l'agent (liste noire) ?

## Dépendances & risques

- Dépend des specs 01 (adaptateur) et 02 (sécurité).
- Risque : un changement de permaliens ou de langue peut perturber le site →
  classification « à risque » et sauvegarde préalable obligatoires.
- Risque : escalade de privilèges via la gestion des utilisateurs → garde-fous
  stricts, l'agent ne peut pas s'auto-élever ni toucher à son propre compte.
