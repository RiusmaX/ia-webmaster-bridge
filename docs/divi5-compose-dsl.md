# DSL de composition Divi 5

> Référence rapide pour `iawm_divi_page_compose` et
> `iawm_divi_theme_builder_compose`.

Ces deux outils MCP prennent un tableau de **sections** où chaque
section peut être décrite selon **trois modes** mixables dans la même
page :

1. **Pattern** — raccourci pour un cas standard (1-3 lignes).
2. **Free-form** — improvisation à partir des modules de base.
3. **Block brut** — escape hatch (JSON Divi déjà composé).

## Mode 1 — Pattern

```js
{ pattern: "<nom>", options: { ... } }
```

13 patterns disponibles :

| Pattern | Usage | Options principales |
|---------|-------|---------------------|
| `hero` | Section d'ouverture (H1 + sous-titre + CTA) | `title`, `subtitle`, `ctaText`, `ctaUrl`, `backgroundColor?`, `backgroundImageUrl?` |
| `features3col` | 3 bénéfices avec icônes | `items: [{title, contentHtml, iconUnicode?, imageUrl?}]`, `sectionTitle?`, `sectionSubtitle?` |
| `ctaBanner` | Bandeau d'appel à l'action | `title`, `contentHtml`, `buttonText`, `buttonUrl`, `backgroundColor?` |
| `imageTextSplit` | 2 colonnes image \| texte | `imageUrl`, `title`, `contentHtml`, `imageOnLeft?`, `backgroundColor?` |
| `testimonials` | Grille de témoignages (1/2/3 col) | `items: [{quoteHtml, author, portraitUrl?}]`, `sectionTitle?` |
| `faqAccordion` | FAQ accordion (1er ouvert) | `items: [{question, answerHtml}]`, `openFirst?`, `sectionTitle?` |
| `numbersBar` | Barre de KPIs animés | `items: [{label, number, percent?}]`, `backgroundColor?` |
| `videoSection` | Vidéo pleine largeur | `videoUrl`, `sectionTitle?`, `sectionSubtitle?` |
| `contactSection` | Formulaire de contact | `fields?` (défaut Nom/Email/Message), `sectionTitle?` |
| `pricing3col` | 3 plans tarifaires | `plans: [{title, price, features: [{text, included?}], buttonText?, buttonUrl?}]` |
| `teamGrid` | Grille de membres | `members: [{name, position, imageUrl?, bioHtml?}]`, `columnsCount?` |
| `headerSimple` | Header logo + menu (2 cols) | `logoUrl?`, `siteName?`, `menuId?`, `backgroundColor?`, `paddingY?` |
| `footerStandard` | Footer multi-colonnes | `columns: [{title, contentHtml?, menuId?, listItems?}]`, `socialNetworks?`, `copyright?`, `backgroundColor?` |

## Mode 2 — Free-form section

```js
{
  section: {
    background?: { color?: <DiviColor>, imageUrl?: <url> },
    spacing?: { padding?: <Spacing>, margin?: <Spacing> },
    rows: [
      {
        structure: "<columnStructure>",   // ex. "1_3,1_3,1_3"
        wrapMobile?: true,                 // colonnes en pile sur mobile (défaut: true)
        spacing?: { padding?, margin? },
        columns: [
          // Soit liste directe de modules :
          [ <ModuleInput>, <ModuleInput>, ... ],
          // Soit objet enrichi :
          {
            type?: "1_3",                  // override de la structure (rare)
            fullWidthOnMobile?: true,
            modules: [ <ModuleInput>, ... ],
          },
        ],
      },
      // ... d'autres rows
    ],
  },
}
```

### Types utilitaires

```ts
DiviColor   = "#RRGGBB" | { gcid: "gcid-primary-color" | "gcid-heading-color" | ... }
Spacing     = { top?: "20px", right?, bottom?, left?, syncVertical?: "on"|"off", syncHorizontal?: "on"|"off" }
columnStructure = "4_4" | "1_2,1_2" | "1_3,1_3,1_3" | "1_4,1_4,1_4,1_4" | "1_3,2_3" | "2_3,1_3" | "1_4,3_4" | "3_4,1_4"
```

## Modules disponibles (`ModuleInput`)

Chaque module = `{ module: "<nom>", ...options }`.

### Contenu de base
```js
{ module: "text",    html: "<h2>...</h2>", headingFont?: { h2: { size, weight, textAlign, ... } } }
{ module: "heading", text: "Titre H1" }
{ module: "blurb",   title, contentHtml, iconUnicode?, imageUrl? }
{ module: "cta",     title, contentHtml, buttonText, buttonUrl }
{ module: "image",   src, alt? }
{ module: "button",  text, linkUrl }
```

### Stats et chiffres
```js
{ module: "number-counter", title, number: "247", percent?: false }
{ module: "circle-counter", title, number: "85" }
{ module: "counters",       items: [{title, progress: "75"}, ...], showPercentages?: true }
```

### Personnes / témoignages
```js
{ module: "testimonial", quoteHtml: "<p>...</p>", author, portraitUrl? }
{ module: "team-member", name, position, imageUrl?, bioHtml? }
```

### Médias
```js
{ module: "gallery", ids: [32,33,34], columns?: 4 }
{ module: "video",   src: "https://youtube.com/..." }
{ module: "audio",   title, artistName?, audioUrl? }
{ module: "code",    html: "<div>...</div>" }    // à éviter
```

### Layout / décor
```js
{ module: "divider", color?, height? }
{ module: "icon",    unicode: "&#xe0e1;", color?, size? }
{ module: "toggle",  title, contentHtml }
```

### Formulaires
```js
{ module: "signup", title, contentHtml }
{ module: "map",    address?, zoom? }
{ module: "contact-form",
  fields: [
    { id: "Name",    label: "Nom",          type: "input" },
    { id: "Email",   label: "Adresse email",type: "email" },
    { id: "Message", label: "Votre message",type: "text", fullwidth: true },
  ],
}
```

### Navigation / theme builder
```js
{ module: "menu",            menuId?: 5, logoUrl?, dropdownDirection? }
{ module: "fullwidth-menu",  ... }                                  // même options
{ module: "search",          placeholder?, buttonText? }
{ module: "breadcrumbs",     homeText?, separator?, htmlTag? }
{ module: "post-title",      includeMeta?, includeFeaturedImage? }   // theme builder
{ module: "post-content" }                                           // injecte le contenu courant
{ module: "post-navigation", prevText?, nextText?, sameTerm? }
{ module: "comments" }
```

### Composés (nested)
```js
{ module: "accordion", items: [
    { title: "Q1", contentHtml: "<p>R1</p>", open: true },
    { title: "Q2", contentHtml: "<p>R2</p>" },
] }

{ module: "tabs", items: [
    { title: "Tab 1", contentHtml: "<p>...</p>" },
    { title: "Tab 2", contentHtml: "<p>...</p>" },
] }

{ module: "slider", items: [
    { title, contentHtml, buttonText?, buttonUrl? },
] }

{ module: "pricing-tables", items: [
    {
      title: "Plan A", subtitle?, price: "29", currency?: "€", frequency?: "month",
      features: [
        { text: "Inclus 1" },                  // included: true (défaut)
        { text: "Non inclus", included: false },
      ],
      buttonText?, buttonUrl?,
    },
] }

{ module: "icon-list", items: [
    { text: "Ligne 1", iconUnicode?: "&#x21;", url?, newTab? },
] }

{ module: "social-media-follow", networks: [
    { network: "facebook",  label?: "Facebook" },
    { network: "instagram", label?: "Instagram" },
] }
```

## Mode 3 — Block brut (escape)

```js
{ block: <GutenbergBlock JSON brut> }
```

Pour les cas exotiques (modules custom, attributs très fins,
composition spéciale). À utiliser **rarement**.

## Exemple complet — page d'accueil mix patterns + free-form

```js
iawm_divi_page_compose({
  post_id: 82,
  sections: [
    // Hero pattern standard
    { pattern: "hero", options: {
      title: "Mon Service",
      subtitle: "Description courte",
      ctaText: "En savoir plus",
      ctaUrl: "#services",
    } },

    // Section free-form personnalisée
    {
      section: {
        background: { color: { gcid: "gcid-body-color" } },
        spacing: { padding: { top: "80px", bottom: "80px", syncVertical: "on", syncHorizontal: "off" } },
        rows: [
          {
            structure: "1_2,1_2",
            wrapMobile: true,
            columns: [
              [
                { module: "text", html: "<h2>Notre approche</h2><p>...</p>" },
                { module: "icon-list", items: [
                  { text: "Avantage 1" },
                  { text: "Avantage 2" },
                  { text: "Avantage 3" },
                ] },
                { module: "button", text: "Découvrir", linkUrl: "/services" },
              ],
              [
                { module: "image", src: "https://...", alt: "..." },
              ],
            ],
          },
        ],
      },
    },

    // 3 KPIs en pattern
    { pattern: "numbersBar", options: {
      items: [
        { label: "Clients", number: "247" },
        { label: "Projets", number: "1.2k" },
        { label: "Satisfaction", number: "98", percent: true },
      ],
    } },

    // FAQ
    { pattern: "faqAccordion", options: {
      items: [
        { question: "Q1 ?", answerHtml: "<p>R1</p>" },
        { question: "Q2 ?", answerHtml: "<p>R2</p>" },
      ],
    } },

    // CTA final
    { pattern: "ctaBanner", options: {
      title: "Prêt à démarrer ?",
      contentHtml: "<p>Premier RDV gratuit.</p>",
      buttonText: "Réserver",
      buttonUrl: "/contact",
    } },
  ],
})
```

## Pour le Theme Builder

```js
iawm_divi_theme_builder_compose({
  title: "Default Site Template",
  header_sections: [
    // Free-form pour un header sur-mesure :
    {
      section: {
        background: { color: "#003366" },
        spacing: { padding: { top: "15px", bottom: "15px", syncVertical: "on", syncHorizontal: "off" } },
        rows: [
          {
            structure: "1_4,1_2,1_4",
            columns: [
              [{ module: "text", html: "<h1>Logo</h1>" }],
              [{ module: "menu" }],
              [{ module: "button", text: "Contact", linkUrl: "/contact" }],
            ],
          },
        ],
      },
    },
  ],
  footer_sections: [
    { pattern: "footerStandard", options: {
      columns: [
        { title: "À propos", contentHtml: "<p>...</p>" },
        { title: "Navigation", menuId: 2 },
        { title: "Contact", contentHtml: "<p>...</p>" },
      ],
      socialNetworks: [
        { network: "facebook" },
        { network: "instagram" },
      ],
      copyright: "© 2026 Mon Site",
    } },
  ],
  // body_sections : omettre = Divi affiche le post_content natif (recommandé pour le default).
  replace_existing: true,
})
```
