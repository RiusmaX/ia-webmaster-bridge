# Format Divi 5 — rétro-ingénierie

> Source : pages de référence n°19 et n°29 sur le site local, peuplées
> dans le Visual Builder Divi 5.5.2 puis lues via `iawm_content_get`.
> **Catalogue complet des modules natifs** dans
> [`divi5-modules-catalog.md`](divi5-modules-catalog.md) — ~99 modules
> natifs inventoriés (21 implémentés côté builders TS).
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

## Modules avancés (page de référence n°29)

Documentés à partir de la 2e page de référence peuplée dans le builder dans
le builder Divi 5.5.2.

### `wp:divi/heading`

Module dédié pour les titres (alternative au `<h1>` dans `wp:divi/text`).

```json
{
  "title": { "innerContent": { "desktop": { "value": "Your Title" } } }
}
```

Plus simple que `wp:divi/text` quand on veut juste un titre. La balise
HTML produite (`<h1>`, `<h2>`…) se règle via `module.advanced.headingLevel`.

### `wp:divi/button`

Bouton seul (à distinguer du bouton intégré au CTA / slide).

```json
{
  "button": {
    "innerContent": {
      "desktop": {
        "value": { "text": "Click Here", "linkUrl": "..." }
      }
    }
  }
}
```

**Astuce** : `linkUrl` peut être une **variable de contenu Divi** :

```
$variable({"type":"content","value":{"name":"home_url","settings":{}}})$
```

Variables de contenu connues : `home_url`, et probablement `page_url`,
`site_url`, etc. Utile pour ne pas hardcoder les URLs.

### `wp:divi/number-counter`

Chiffre animé au scroll (idéal pour les KPIs).

```json
{
  "title":  { "innerContent": { "desktop": { "value": "Clients" } } },
  "number": {
    "innerContent": { "desktop": { "value": "247" } },
    "advanced":     { "enablePercentSign": { "desktop": { "value": "off" } } }
  }
}
```

`enablePercentSign` ajoute un `%` automatique (utile pour les taux).

### `wp:divi/testimonial`

Citation avec photo + nom.

```json
{
  "content":  { "innerContent": { "desktop": { "value": "<p>Quote…</p>" } } },
  "author":   { "innerContent": { "desktop": { "value": "Name" } } },
  "portrait": {
    "innerContent": {
      "desktop": { "value": { "url": "..." } }
    }
  }
}
```

Champ `job_title` (fonction) probablement aussi disponible — à vérifier.

### `wp:divi/gallery`

Galerie d'images, **liste d'IDs media en CSV**.

```json
{
  "image": {
    "advanced": {
      "galleryIds": { "desktop": { "value": "32,33,34,35,36,37,38" } }
    }
  },
  "galleryGrid": {
    "decoration": {
      "layout": {
        "tablet": { "value": { "gridColumnCount": "3" } },
        "phone":  { "value": { "gridColumnCount": "1" } }
      }
    }
  }
}
```

Les IDs renvoient à des attachments WP (post_type=attachment). À générer
on uploade d'abord les images via `iawm_media_sideload` puis on
récupère leurs IDs.

### `wp:divi/video`

Vidéo, **URL YouTube/Vimeo auto-détectée**.

```json
{
  "video": {
    "innerContent": {
      "desktop": { "value": { "src": "https://www.youtube.com/watch?v=…" } }
    }
  }
}
```

Pour une vidéo self-hosted, fournir l'URL `.mp4` directement.

### `wp:divi/code`

Bloc HTML brut. **À éviter sauf nécessité** — contourne la logique du
builder et complique la maintenance.

```json
{
  "content": { "innerContent": { "desktop": { "value": "<div>...</div>" } } }
}
```

### Modules composés (nested)

Quatre modules contiennent des enfants : `accordion`, `tabs`, `slider`,
`contact-form`. Leurs enfants sont des blocs Divi à part entière dans
`innerBlocks`.

#### `wp:divi/accordion` + `wp:divi/accordion-item`

```
wp:divi/accordion
└── wp:divi/accordion-item × N
```

Item :
```json
{
  "title":   { "innerContent": { "desktop": { "value": "Question ?" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>Réponse…</p>" } } },
  "module": {
    "advanced": { "open": { "desktop": { "value": "on" } } }  // optionnel
  }
}
```

`open: "on"` ouvre l'item par défaut (souvent posé sur le premier).

#### `wp:divi/tabs` + `wp:divi/tab`

```
wp:divi/tabs
└── wp:divi/tab × N
```

Tab :
```json
{
  "title":   { "innerContent": { "desktop": { "value": "Tab Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>…</p>" } } }
}
```

#### `wp:divi/slider` + `wp:divi/slide`

```
wp:divi/slider
└── wp:divi/slide × N
```

Slide :
```json
{
  "title":   { "innerContent": { "desktop": { "value": "Slide Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>…</p>" } } },
  "button":  {
    "innerContent": {
      "desktop": { "value": { "text": "Click Here", "linkUrl": "#" } }
    }
  }
}
```

#### `wp:divi/contact-form` + `wp:divi/contact-field`

```
wp:divi/contact-form
└── wp:divi/contact-field × N
```

Le form a un **uniqueId** UUID (auto-généré par le builder, à régénérer
côté nous via `wp_generate_uuid4()` ou équivalent JS) :

```json
{
  "module": {
    "advanced": {
      "uniqueId": { "desktop": { "value": "aa2b25f7-44fc-41da-af32-b665cedb10d0" } }
    }
  }
}
```

Field :
```json
{
  "fieldItem": {
    "advanced": {
      "fullwidth": { "desktop": { "value": "on" } },
      "id":        { "desktop": { "value": "Name" } },
      "type":      { "desktop": { "value": "input" } }
    },
    "innerContent": { "desktop": { "value": "Name" } }
  },
  "module": {
    "decoration": {
      "sizing": { "desktop": { "value": { "flexType": "12_24" } } }
    }
  }
}
```

**Types de field** : `input` (texte court), `email`, `text` (textarea),
probablement aussi `select`, `checkbox`, `radio`, `phone`. `flexType`
contrôle la largeur (12_24 = demi, 24_24 = pleine).

`id` est l'identifiant interne du champ (utilisé dans les notifications
email reçues par le destinataire). À donner sans espaces.

## Modules natifs prioritaires (page de référence n°53)

Documentés à partir de la 3e page de référence peuplée dans le builder.

### `wp:divi/divider`

Séparateur visuel. Avec valeurs par défaut, attributs minimaux. Options
de style (couleur, hauteur, alignement) dans `module.decoration.divider`.

```json
{ "builderVersion": "5.5.2" }
```

### `wp:divi/icon`

Icône Divi seule. Par défaut, attributs minimaux. La configuration
(unicode + couleur + taille) est dans `icon.innerContent.desktop.value`.

```json
{
  "icon": {
    "innerContent": {
      "desktop": { "value": { "unicode": "&#x21;", "type": "divi", "weight": "400" } }
    }
  }
}
```

### `wp:divi/toggle`

Bloc révélable (équivalent à un accordion à 1 item).

```json
{
  "title":   { "innerContent": { "desktop": { "value": "Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>…</p>" } } }
}
```

### `wp:divi/pricing-tables` + `wp:divi/pricing-table` ⚠️

**Convention de nommage spécifique** : le conteneur est *tables* (pluriel)
et l'enfant est *table* (singulier), pas *table-item*.

```
wp:divi/pricing-tables
└── wp:divi/pricing-table × N
```

Item :
```json
{
  "title":    { "innerContent": { "desktop": { "value": "Table Title" } } },
  "subtitle": { "innerContent": { "desktop": { "value": "Subtitle" } } },
  "currencyFrequency": {
    "innerContent": {
      "desktop": { "value": { "currency": "$" } }
    }
  },
  "price":   { "innerContent": { "desktop": { "value": "50" } } },
  "content": {
    "innerContent": {
      "desktop": {
        "value": "+ Inclus 1\n+ Inclus 2\n- Non inclus"
      }
    }
  }
}
```

`content` est un texte multi-lignes où chaque ligne commence par :
- `+` pour une fonctionnalité **incluse**
- `-` pour une fonctionnalité **non incluse**

### `wp:divi/icon-list` + `wp:divi/icon-list-item`

Liste à puces stylées (icône + texte par item).

Item :
```json
{
  "content": { "innerContent": { "desktop": { "value": "List item text" } } },
  "icon": {
    "innerContent": {
      "desktop": {
        "value": {
          "unicode": "&#x21;",
          "type":    "divi",
          "weight":  "400",
          "target":  "off"
        }
      }
    }
  }
}
```

`target: "on"` ouvre le lien dans un nouvel onglet (si l'item est lié).

### `wp:divi/social-media-follow` + `wp:divi/social-media-follow-network` ⚠️

**Convention** : l'enfant s'appelle `*-network` (pas `*-item`).

Item :
```json
{
  "socialNetwork": {
    "innerContent": {
      "desktop": {
        "value": { "title": "facebook", "label": "Facebook" }
      }
    }
  },
  "module": {
    "decoration": {
      "background": {
        "desktop": { "value": { "color": "#3b5998" } }
      }
    }
  }
}
```

`title` = identifiant interne du réseau (`facebook`, `twitter`,
`instagram`, `linkedin`, `youtube`, `tiktok`, etc.). `label` = texte
affiché. La couleur de fond est typiquement celle de la marque.

### `wp:divi/team-member`

Membre d'équipe (photo + nom + fonction + bio).

```json
{
  "name":     { "innerContent": { "desktop": { "value": "Name" } } },
  "position": { "innerContent": { "desktop": { "value": "Position" } } },
  "image": {
    "innerContent": {
      "desktop": { "value": { "url": "https://..." } }
    }
  },
  "content": { "innerContent": { "desktop": { "value": "<p>Bio…</p>" } } }
}
```

⚠️ Note : l'image utilise `url` (pas `src` comme dans `wp:divi/image`).
Le module gère aussi les profils sociaux du membre via des attributs
avancés (facebook, twitter, …).

### `wp:divi/signup`

Email opt-in (capture newsletter).

```json
{
  "title":   { "innerContent": { "desktop": { "value": "Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>…</p>" } } }
}
```

Le provider (Mailchimp, ConvertKit, etc.) et la liste de destination
sont configurés dans des attributs avancés non observés ici.

### `wp:divi/map`

Google Maps. Avec valeurs par défaut, attributs minimaux. L'adresse,
le zoom et les marqueurs (modules `wp:divi/map-item` enfants) se posent
en attributs avancés.

```json
{ "builderVersion": "5.5.2" }
```

### `wp:divi/circle-counter`

Variation circulaire du number counter (pourcentage en cercle animé).

```json
{
  "title":  { "innerContent": { "desktop": { "value": "Title" } } },
  "number": { "innerContent": { "desktop": { "value": "50" } } }
}
```

Par défaut affiché en pourcentage (de 0 à `number`).

### `wp:divi/counters` + `wp:divi/counter` ⚠️

**Bar counters** : le blockName est **`divi/counters`** (et non
`divi/bar-counters` comme suggéré par le nom de classe `BarCounters`).
Item = `divi/counter` (singulier).

Conteneur :
```json
{
  "barProgress": {
    "advanced": {
      "usePercentages": { "desktop": { "value": "on" } }
    }
  }
}
```

Item :
```json
{
  "title":      { "innerContent": { "desktop": { "value": "Skill" } } },
  "barProgress":{ "innerContent": { "desktop": { "value": "50" } } }
}
```

### `wp:divi/audio`

Lecteur audio HTML5.

```json
{
  "title":      { "innerContent": { "desktop": { "value": "Track Title" } } },
  "artistName": { "innerContent": { "desktop": { "value": "Artist" } } }
}
```

L'URL du fichier audio est dans un attribut `audio` ou similaire (à
confirmer en peuplant l'URL dans le builder).

## Variables Divi (au-delà des couleurs)

On a vu `$variable({"type":"color",...})$` pour les couleurs globales.
**Le même mécanisme** sert pour d'autres types :

| `type`     | Utilisation | Exemples de `name` observés |
|-----------|-------------|------------------------------|
| `color`   | Couleur globale | `gcid-primary-color`, `gcid-heading-color`, … |
| `content` | Contenu dynamique | `home_url`, probablement `page_url`, `post_title`, … |

D'autres types possibles (à confirmer) : `font`, `text`, `image`,
`number`, `link`.
