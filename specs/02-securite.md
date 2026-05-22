# Spec 02 — Sécurité & garde-fous

- **Statut** : Ébauche
- **Phase** : Transversale (construite dès la Phase 1, durcie en Phase 5)
- **Priorité** : Haute
- **Dernière mise à jour** : 2026-05-21

## Objectif

Garantir qu'un agent doté des pleins pouvoirs de webmaster ne devienne pas une
faille. Couvre l'authentification des échanges, l'autorisation, la traçabilité
et les garde-fous contre les opérations dangereuses (décision D-005).

## Périmètre

### Inclus
- Authentification et intégrité des échanges pont ↔ plugin.
- Modèle d'autorisation (capacités scoppées).
- Journal d'audit de toutes les actions.
- Garde-fous sur les opérations destructrices.
- Gestion des secrets et kill switch.

### Exclu (pour l'instant)
- La sécurité générale du serveur WordPress (durcissement hébergement) — du
  ressort de l'hébergeur / de l'utilisateur, hors périmètre logiciel du projet.

## Approche technique

### Authentification & intégrité
- **HTTPS imposé** sur les prod ; toléré en HTTP sur le local (documenté).
- **Clé d'API** dédiée à l'agent, distincte par cible.
- **Signature HMAC** de chaque requête : le pont signe (méthode + chemin + corps
  + horodatage + nonce) avec un secret partagé ; le plugin vérifie. Protège
  contre l'altération et le rejeu.
- **Fenêtre temporelle** courte sur l'horodatage pour limiter le rejeu.
- Pas d'Application Password en Basic Auth simple : insuffisant (pas
  d'expiration, pas d'intégrité du corps).

### Autorisation
- Un **utilisateur WordPress dédié** à l'agent, avec un rôle au moindre privilège.
- Les capacités sont **scoppées** : la clé d'API porte une liste de scopes
  (ex. `content:read`, `content:write`, `divi:write`, `infra:*`). Une capacité
  hors scope est refusée.
- Profils de prudence : sur une nouvelle cible, démarrer en lecture seule, puis
  élargir.

### Journal d'audit
- Le plugin journalise **chaque appel de capacité** : date, capacité, paramètres
  (résumés), résultat, identité de la clé. Stockage interrogeable.
- Journal consultable par Claude (capacité de lecture dédiée) pour
  l'auto-vérification.

### Garde-fous
- **Mode dry-run** : toute capacité d'écriture peut être appelée en simulation
  (retourne ce qu'elle ferait, sans l'appliquer).
- **Brouillon avant publication** : les contenus créés le sont en brouillon par
  défaut ; la publication est une étape explicite.
- **Sauvegarde avant destructif** : toute opération destructrice ou risquée
  (suppression, mise à jour d'extension, opération base de données) déclenche
  une sauvegarde préalable.
- **Confirmation explicite** : les actions classées « à risque » exigent un
  jeton de confirmation distinct de l'appel initial.
- **Kill switch** : un réglage du plugin désactive instantanément toutes les
  capacités d'écriture.

### Gestion des secrets
- Les secrets (clés, URL, identifiants) vivent dans la config du pont, **hors du
  dépôt** (fichier ignoré par Git / variables d'environnement).
- Rotation des clés prévue ; révocation côté plugin.
- Optionnel : liste d'IP autorisées côté plugin pour les prod.

## Points ouverts

- Stockage du journal d'audit : table custom, fichier, ou les deux ?
- Classification précise « action à risque » → liste à établir par plan.
- Mécanisme exact du jeton de confirmation (TTL, usage unique).
- Faut-il chiffrer les paramètres sensibles dans le journal ?
- Politique de rotation des clés (fréquence, procédure).

## Dépendances & risques

- Transversale : toutes les autres specs s'appuient sur ce modèle.
- Risque : un garde-fou trop strict freine l'agent ; un trop laxiste crée un
  danger. À calibrer par l'expérience, cible par cible.
