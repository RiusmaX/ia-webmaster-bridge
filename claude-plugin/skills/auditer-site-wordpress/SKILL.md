---
name: auditer-site-wordpress
description: Auditer l'état d'un site WordPress connecté — santé technique, extensions et thèmes, contenu, configuration, logs — et produire un rapport structuré.
---

# Auditer un site WordPress

Workflow pour dresser un état des lieux complet d'un site via l'adaptateur.

## 1. Santé technique

- `iawm_diagnostics_system` — versions (WordPress, PHP, MySQL), thème actif,
  limites PHP, mode débogage.
- `iawm_diagnostics_logs` — erreurs récentes dans le debug.log.

## 2. Extensions et thèmes

- `iawm_diagnostics_plugins` — extensions installées, actives/inactives, et
  surtout les **mises à jour disponibles**.
- `iawm_diagnostics_themes` — thèmes installés.

## 3. Inventaire du contenu

- `iawm_content_list` (pages, puis articles) — volume, brouillons en attente.
- `iawm_media_list` — médiathèque.
- `iawm_menu_list` — menus et emplacements assignés.

## 4. Configuration

- `iawm_config_settings_get` — réglages clés : indexation (`blog_public`), page
  d'accueil, structure des permaliens.
- `iawm_config_users_list` — comptes et rôles ; repérer les comptes inattendus.

## 5. Activité récente

- `iawm_audit` — dernières actions effectuées via l'adaptateur.

## Rapport

Produire un rapport clair, regroupé par thème — **Technique**, **Extensions**,
**Contenu**, **Configuration**, **Sécurité** — avec pour chaque point : le
constat, un niveau de priorité, et une recommandation concrète. Mettre en avant
les mises à jour en attente et tout réglage à risque.
