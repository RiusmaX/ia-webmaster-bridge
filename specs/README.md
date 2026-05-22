# Specs par fonctionnalité

Ce dossier contient une spec par fonctionnalité du système. Les specs sont des
**documents vivants** : à ce stade du projet (conception, on teste et itère),
elles cadrent le périmètre et l'approche, pas l'implémentation ligne à ligne.

## Index

| # | Spec | Objet | Phase | Priorité |
|---|------|-------|-------|----------|
| 01 | [`01-adaptateur.md`](01-adaptateur.md) | Plugin WordPress + pont MCP local | 1–2 | Haute |
| 02 | [`02-securite.md`](02-securite.md) | Authentification, audit, garde-fous | Transversale | Haute |
| 03 | [`03-contenu.md`](03-contenu.md) | Pages, articles, médias, menus, blocs | 2 | Haute |
| 04 | [`04-divi5.md`](04-divi5.md) | Génération de layouts Divi 5 | 3 | **Prioritaire** |
| 05 | [`05-configuration.md`](05-configuration.md) | Réglages, thème, utilisateurs | 2 | Moyenne |
| 06 | [`06-infrastructure.md`](06-infrastructure.md) | Extensions, base de données, sauvegardes | 4 | Moyenne |
| 07 | [`07-couche-webmaster.md`](07-couche-webmaster.md) | Skills et workflows webmaster | 6 | Moyenne |

## Convention de statut

Chaque spec porte un statut en en-tête :

- **Ébauche** — cadrage initial, beaucoup de points ouverts.
- **En conception** — approche en cours de stabilisation.
- **Validée** — approche arrêtée, prête à implémenter.
- **En implémentation** — code en cours.
- **Implémentée** — livrée et vérifiée.

## Règle de maintenance

Toute modification d'une spec doit mettre à jour son champ « Statut » et sa date.
Toute décision structurante est aussi reportée dans `../docs/decisions.md`.
