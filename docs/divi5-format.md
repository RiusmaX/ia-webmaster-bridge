# Format Divi 5 — rétro-ingénierie

> Source : page de référence n°19 (`IAWM Divi Reference`) sur le site local,
> peuplée dans le Visual Builder Divi 5.5.2 puis lue via
> `iawm_content_get`.
> Dernière mise à jour : 2026-05-23.

Cette doc fixe ce qu'on a **observé directement**, pas ce que la doc
communautaire suppose. À chaque évolution majeure de Divi, refaire un
round-trip sur cette page.

## TL;DR

Une page Divi 5 est stockée dans `post_content` au format **Gutenberg
blocks Divi**, avec une hiérarchie de cinq niveaux :

```
wp:divi/placeholder              ← wrapper racine (1 par page Divi)
└── wp:divi/section              ← bloc « bande » pleine largeur
    └── wp:divi/row              ← ligne dans la section
        └── wp:divi/column       ← colonne (1, 2, 3 ou 4 dans la row)
            └── modules          ← text, blurb, cta, image, button…
```

Chaque bloc Gutenberg contient un **JSON d'attributs** (encodé en
JSON-escape `"` pour les `"`) avec deux racines récurrentes :

- **`module`** — paramètres structurels et style (decoration, advanced, spacing, sizing…).
- **`content`** (modules texte) / **`title`** / **`imageIcon`** / **`image`** / **`button`** — contenu et données spécifiques au module.

Plus un `builderVersion` à la racine (ex. `"5.5.2"`).

## Wrapper racine `wp:divi/placeholder`

Toute page Divi 5 commence par :

```html
<!-- wp:divi/placeholder -->
…sections…
<!-- /wp:divi/placeholder -->
```

C'est ce wrapper qui signale à Divi qu'il faut prendre le contrôle du
rendu de la page (à la place du thème WordPress par défaut). Sans lui,
les blocs Divi peuvent ne pas s'afficher correctement.

> ⚠️ La meta `_et_pb_use_builder = 'on'` reste également nécessaire pour
> que Divi reconnaisse la page comme « builderée ». Le wrapper seul ne
> suffit pas.

## Hiérarchie obligatoire

L'arbre **doit** respecter exactement cet ordre :

| Niveau | Bloc                      | Contraintes |
|--------|---------------------------|-------------|
| 1      | `wp:divi/placeholder`     | 1 unique, racine |
| 2      | `wp:divi/section`         | N sections en séquence |
| 3      | `wp:divi/row`             | 1 ou N rows dans une section |
| 4      | `wp:divi/column`          | Dépend de `columnStructure` de la row |
| 5      | Modules (`wp:divi/text`, …) | N modules par colonne |

Casser cet ordre = page non rendue ou avec erreurs visibles dans le VB.

## Format des attributs

Le JSON d'attributs est encodé avec **`"` au lieu de `"`** dans les
valeurs (échappement Gutenberg standard). Quand on parse côté PHP avec
`parse_blocks()`, on récupère des structures associatives natives.

### Structure générale par bloc

```json
{
  "module": {
    "advanced": { /* type, columnStructure, link, position… */ },
    "decoration": { /* background, spacing, layout, sizing, font… */ }
  },
  "content": { "innerContent": { /* par breakpoint */ } },
  "title":   { "innerContent": { /* par breakpoint */ } },
  "builderVersion": "5.5.2"
}
```

Les blocs **structurels** (section/row/column) ont surtout `module`. Les
blocs **contenu** (text/blurb/cta/image/button) ajoutent un ou plusieurs
champs spécifiques (`content`, `title`, `image`, `imageIcon`, `button`).

### Multi-breakpoints

Toutes les propriétés stylables suivent le pattern :

```json
{
  "decoration": {
    "background": {
      "desktop": { "value": { "color": "#fff" } },
      "phone":   { "value": { "color": "#000" } }
    }
  }
}
```

**Breakpoints observés** : `desktop`, `tablet`, `phoneWide`, `phone`.
(Divi 5 supporte aussi des breakpoints custom — non rencontrés ici).

Si seul `desktop` est défini, Divi en hérite pour tous les autres.

### Variables globales (design system)

Une valeur peut référencer une **variable globale** au lieu d'une
constante :

```
$variable({"type":"color","value":{"name":"gcid-heading-color","settings":{}}})$
```

Le format : `$variable( <JSON> )$` où le JSON contient :
- `type` : `color` (vu), probablement aussi `font`, etc.
- `value.name` : identifiant de la variable (`gcid-*` = global color id).
- `value.settings` : surcharges éventuelles.

Ces variables sont définies via `divi/v1/global-data/global-colors` /
`global-fonts` / `global-variables`. **Toujours préférer la variable** à
la valeur en dur : changer la palette globale propage le changement.

### Icônes Divi

Les modules type `blurb` peuvent afficher une icône Divi :

```json
"icon": {
  "unicode": "&#x5a;",
  "type":    "divi",
  "weight":  "400"
}
```

Où `unicode` est le code-point de l'icône dans la police d'icônes Divi
(format `&#xXXXX;`).

### Couleurs et gradients

Couleur unie :
```json
"background": { "desktop": { "value": { "color": "#2B87DA" } } }
```

Gradient :
```json
"background": {
  "desktop": {
    "value": {
      "color": "...",
      "gradient": {
        "enabled": "on",
        "stops": [
          {"position": "0",   "color": "#2B87DA"},
          {"position": "100", "color": "#29C4A9"}
        ]
      }
    }
  }
}
```

### Image de fond

```json
"background": {
  "desktop": {
    "value": {
      "image": { "url": "http://.../image.jpg" }
    }
  }
}
```

### Spacing (padding/margin)

```json
"spacing": {
  "desktop": {
    "value": {
      "padding": {
        "top":            "150px",
        "bottom":         "150px",
        "syncVertical":   "on",
        "syncHorizontal": "off"
      },
      "margin": {
        "left":           "334px",
        "syncVertical":   "off",
        "syncHorizontal": "off"
      }
    }
  }
}
```

`syncVertical` / `syncHorizontal` lient top↔bottom / left↔right pour
édition synchronisée dans le VB.

## Modules structurels

### `wp:divi/section`

```json
{
  "module": {
    "decoration": {
      "background": { "desktop": { "value": { "color": "..." } } },
      "spacing":    { /* padding, margin */ }
    }
  },
  "builderVersion": "5.5.2"
}
```

Une section = une bande horizontale pleine largeur.

### `wp:divi/row`

```json
{
  "module": {
    "advanced": {
      "columnStructure":     { "desktop": { "value": "1_3,1_3,1_3" } },
      "flexColumnStructure": { "desktop": { "value": "equal-columns_3" } }
    },
    "decoration": {
      "layout": {
        "desktop": { "value": { "flexWrap": "nowrap" } },
        "phone":   { "value": { "flexWrap": "wrap"   } }
      }
    }
  }
}
```

**`columnStructure`** : décrit les colonnes en notation `a_b` où `a/b`
est la fraction occupée. Combinaisons observées :
- `"4_4"` — 1 colonne pleine largeur
- `"1_2,1_2"` — 2 colonnes égales
- `"1_3,1_3,1_3"` — 3 colonnes égales
- (probablement `"1_4,1_4,1_4,1_4"`, `"1_2,1_4,1_4"`, etc.)

**`flexColumnStructure`** : redondance pour le moteur flex (Divi 5
utilise flex en interne).

### `wp:divi/column`

```json
{
  "module": {
    "advanced": {
      "type": { "desktop": { "value": "1_3" } }
    },
    "decoration": {
      "sizing": {
        "desktop": { "value": { "flexType": "8_24" } },
        "phone":   { "value": { "flexType": "24_24" } }
      }
    }
  }
}
```

`type` correspond à la part dans `columnStructure`. `flexType` est la
même chose en fraction sur 24 (ex. `8_24` = 1/3, `24_24` = pleine
largeur). Sur mobile (`phone`), les colonnes basculent en pleine
largeur via `flexType: 24_24`.

## Modules de contenu

### `wp:divi/text`

```json
{
  "content": {
    "innerContent": {
      "desktop": {
        "value": "<h1><span>Bienvenue sur IAWM Reference</span></h1>"
      }
    },
    "decoration": {
      "headingFont": {
        "h1": {
          "font": {
            "desktop": {
              "value": {
                "textAlign": "center",
                "style":     ["uppercase"],
                "size":      "28px",
                "weight":    "800"
              }
            }
          }
        }
      }
    }
  }
}
```

Le contenu réel est du **HTML inline** dans `innerContent.{bp}.value`.
La typo des H1–H6 se règle via `decoration.headingFont.{tag}.font`.

### `wp:divi/blurb`

```json
{
  "imageIcon": {
    "innerContent": {
      "desktop": {
        "value": {
          "src":     "data:image/svg+xml;base64,...",
          "useIcon": "on",
          "icon":    { "unicode": "&#x5a;", "type": "divi", "weight": "400" }
        }
      }
    }
  },
  "title":   { "innerContent": { "desktop": { "value": { "text": "Your Title" } } } },
  "content": { "innerContent": { "desktop": { "value": "<p>...</p>" } } }
}
```

Le blurb a trois champs : `imageIcon` (visuel haut), `title`, `content`.
`useIcon: "on"` fait basculer l'image en icône Divi.

### `wp:divi/cta`

```json
{
  "module": {
    "advanced": {
      "link": { "desktop": { "value": { "url": "" } } }
    }
  },
  "title":   { "innerContent": { "desktop": { "value": "Your Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>...</p>" } } },
  "button":  {
    "innerContent": {
      "desktop": {
        "value": { "text": "CHECK THIS", "linkUrl": "#" }
      }
    }
  }
}
```

CTA = titre + texte + bouton, optionnellement lié.

### `wp:divi/image`

```json
{
  "image": {
    "innerContent": {
      "desktop": {
        "value": { "src": "data:image/svg+xml;base64,..." }
      }
    }
  }
}
```

Le module image est minimaliste — l'URL/source dans `image.innerContent`.
Pour un asset WP réel, l'URL pointe vers `wp-content/uploads/...`.

### `wp:divi/button` (présumé, non rencontré ici)

Vu via `wp:divi/cta` qui embarque un bouton, on suppose la même
structure `innerContent.desktop.value.{text, linkUrl}`.

## Pièges identifiés

1. **JSON profond et redondant.** `module.decoration.background.desktop.value.color`
   pour une simple couleur de fond. Toute manipulation programmatique doit
   passer par un constructeur dédié, pas par concaténation manuelle.

2. **Échappement `"`.** Si on écrit du JSON Divi à la main et qu'on
   le réinjecte dans `post_content`, ne **pas** échapper les `"` en
   `"` — c'est `serialize_block()` côté WP qui s'en charge.

3. **Placeholder racine obligatoire.** Une section seule, sans wrapper
   `wp:divi/placeholder`, ne s'affichera pas correctement (ou cassera
   le VB à l'ouverture).

4. **Module + Row + Section = trio indissociable.** Pas de module
   directement dans une row sans column ; pas de column directement
   dans une section sans row. Le builder refuse le chargement sinon.

5. **Variables globales > valeurs en dur.** Sur un site de l'utilisateur, il
   faut systématiquement référencer `gcid-heading-color`, `gcid-body-color`,
   etc. au lieu de `#000`. Sinon, le changement de palette ne se propage
   pas.

6. **Versionning.** Chaque bloc porte un `builderVersion`. À chaque
   évolution majeure de Divi 5, vérifier la compatibilité du format.
   Tester en round-trip systématiquement.

7. **Builds Gutenberg.** `parse_blocks()` + `serialize_blocks()` côté
   WP normalisent l'output. Un round-trip Divi → parse → serialize peut
   **réordonner les clés** du JSON d'attributs (ce n'est pas un bug, c'est
   un artefact de `json_encode`). Le builder accepte les deux ordres.
   En pratique observée sur la page de référence : **round-trip identique
   au bit près** (11438 octets, 24 blocs).

8. **Piège `wp_unslash` (critique).** `wp_insert_post` et `wp_update_post`
   appliquent `wp_unslash()` en interne sur les champs texte — ils
   supposent que les données viennent slashed depuis `$_POST`. Si on leur
   passe directement un `post_content` Divi déjà propre, **tous les
   backslashes disparaissent silencieusement**. Or Divi 5 stocke ses
   attributs avec des échappements Unicode `"`, `<`, `>`,
   etc. — chaque backslash perdu corrompt un attribut. **Toujours appeler
   `wp_slash()` sur le contenu avant `wp_insert_post` / `wp_update_post`.**
   C'est le fix qui rend le round-trip fidèle au bit près.

## Plan d'attaque (Phase 3.2)

1. **Constructeurs PHP côté plugin** (`lib/divi/` côté gateway TS, ou
   `includes/divi/` côté plugin) :
   - `make_section($attrs, $rows)`
   - `make_row($column_structure, $columns)`
   - `make_column($type, $modules)`
   - `make_text($html, $style)`
   - `make_blurb($title, $text, $icon)`
   - `make_cta($title, $text, $button_text, $button_link)`
   - `make_image($src, $alt)`
2. **Sérialiseur** : prend une structure haut niveau (Page→Sections→…→Modules)
   et produit le `post_content` valide, encadré par `wp:divi/placeholder`.
3. **Endpoint `/divi/page/read`** : `parse_blocks` + projection en arbre
   simplifié (sans le bruit `desktop.value`).
4. **Round-trip** : lire la page 19, la réécrire ailleurs, comparer.

## Modules à explorer (TODO)

Encore non rencontrés sur cette page — à peupler en Phase 3.2 :

- `wp:divi/button` (bouton seul)
- `wp:divi/heading` (titre dédié)
- `wp:divi/video`
- `wp:divi/gallery`
- `wp:divi/testimonial`
- `wp:divi/number-counter`
- `wp:divi/form` (formulaire de contact)
- `wp:divi/menu`
- `wp:divi/code` (à éviter sauf nécessité)

Quand on en aura besoin, Marius pourra peupler une 2e page de référence
avec ces modules, et on étendra cette doc.
