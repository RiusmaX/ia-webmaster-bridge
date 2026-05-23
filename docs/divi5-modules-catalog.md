# Catalogue des modules Divi 5 (natifs)

> Source de vérité : `wp-content/themes/Divi/includes/builder-5/server/Packages/ModuleLibrary/`
> sur le site local. Croisé avec la doc officielle Elegant Themes.
> Dernière mise à jour : 2026-05-23.

## Pattern de nommage des blocs

Les modules Divi 5 sont enregistrés comme blocs Gutenberg sous le
namespace `divi/*`. Le `blockName` est le **kebab-case** du nom de
classe PascalCase :

| Classe PHP | blockName |
|-----------|-----------|
| `NumberCounter` | `divi/number-counter` |
| `AccordionItem` | `divi/accordion-item` |
| `BeforeAfterImage` | `divi/before-after-image` |
| `WooCommerceBreadcrumb` | `divi/woocommerce-breadcrumb` (à confirmer) |

## Légende

- ✅ **Documenté + builder TS implémenté** dans `lib/divi/builders.ts`
- 🟢 **Documenté** (format observé sur une page de référence)
- 🟡 **Inventorié** (blockName déduit, format à valider)
- ⚠️ **À confirmer** (peupler une page de référence)

---

## 1. Structurels (3)

Tout le squelette d'une page Divi 5. Hiérarchie obligatoire :
`placeholder > section > row > column > modules`.

| blockName | Statut | Note |
|-----------|--------|------|
| `divi/placeholder` | ✅ | Wrapper racine obligatoire |
| `divi/section` | ✅ | Bande horizontale |
| `divi/row` | ✅ | Avec `columnStructure` (notation `a_b,c_d,...`) |
| `divi/column` | ✅ | `type` (ex. `1_3`), `flexType` auto (`8_24`) |
| `divi/row-inner` | 🟡 | Variante de row pour modules nested |
| `divi/column-inner` | 🟡 | Variante de column pour modules nested |

---

## 2. Modules Contenu de base (12)

Les briques fondamentales du builder. **Tous documentés ou implémentés.**

| blockName | Statut | Builder TS | Pattern |
|-----------|--------|------------|---------|
| `divi/text` | ✅ | `text({ html, headingFont })` | hero / features3col |
| `divi/heading` | ✅ | `heading({ text })` | — |
| `divi/blurb` | ✅ | `blurb({ title, contentHtml, iconUnicode? })` | features3col |
| `divi/cta` | ✅ | `cta({ title, contentHtml, buttonText, buttonUrl })` | ctaBanner |
| `divi/button` | ✅ | `button({ text, linkUrl })` | — |
| `divi/image` | ✅ | `image({ src, alt? })` | imageTextSplit |
| `divi/video` | ✅ | `video({ src })` | videoSection |
| `divi/code` | ✅ | `code({ html })` | (à éviter) |
| `divi/divider` | 🟡 | À ajouter | utile pour séparation visuelle |
| `divi/icon` | 🟡 | À ajouter | code Divi unicode |
| `divi/gallery` | ✅ | `gallery({ ids[], columns? })` | — |
| `divi/audio` | 🟡 | À ajouter | lecteur audio HTML5 |

---

## 3. Modules Composés (nested) (8 groupes)

Modules qui contiennent d'autres blocs Divi en `innerBlocks`.

| Conteneur | Item enfant | Statut | Builder TS |
|-----------|-------------|--------|------------|
| `divi/accordion` | `divi/accordion-item` | ✅ | `accordion([items])` |
| `divi/tabs` | `divi/tab` | ✅ | `tabs([items])` |
| `divi/slider` | `divi/slide` | ✅ | `slider([items])` |
| `divi/contact-form` | `divi/contact-field` | ✅ | `contactForm({ fields })` |
| `divi/toggle` | — (simple) | 🟡 | À ajouter (accordion mais 1 seul item) |
| `divi/pricing-tables` | `divi/pricing-tables-item` | 🟡 | À ajouter — **prioritaire** |
| `divi/icon-list` | `divi/icon-list-item` | 🟡 | À ajouter — utile partout |
| `divi/social-media-follow` | `divi/social-media-follow-item` | 🟡 | À ajouter — footer/header |
| `divi/timeline` | `divi/timeline-item` | 🟡 | À ajouter — histoire entreprise |
| `divi/map` | `divi/map-item` | 🟡 | À ajouter — page contact |
| `divi/video-slider` | `divi/video-slider-item` | 🟡 | À ajouter |
| `divi/bar-counters` | `divi/bar-counters-item` | 🟡 | À ajouter — KPIs animés |

---

## 4. Statistiques et chiffres (4)

| blockName | Statut | Builder TS | Note |
|-----------|--------|------------|------|
| `divi/number-counter` | ✅ | `numberCounter({ title, number, percent? })` | Animation au scroll |
| `divi/circle-counter` | 🟡 | À ajouter | Variante circulaire |
| `divi/bar-counters` (composé) | 🟡 | À ajouter | Barres horizontales |
| `divi/countdown-timer` | 🟡 | À ajouter | Compte à rebours réel |

---

## 5. Personnes et témoignages (2)

| blockName | Statut | Builder TS | Note |
|-----------|--------|------------|------|
| `divi/testimonial` | ✅ | `testimonial({ quoteHtml, author, portraitUrl? })` | Citation + photo |
| `divi/team-member` | 🟡 | À ajouter | Photo + bio + réseaux sociaux |

---

## 6. Portfolio et galeries (5)

| blockName | Statut | Note |
|-----------|--------|------|
| `divi/portfolio` | 🟡 | Grille de projets WP (post_type=project) |
| `divi/filterable-portfolio` | 🟡 | Avec filtres par catégorie |
| `divi/fullwidth-portfolio` | 🟡 | Variante pleine largeur |
| `divi/gallery` | ✅ | Galerie d'images simple |
| `divi/before-after-image` | 🟡 | Slider de comparaison |

---

## 7. Contenu dynamique WordPress (theme builder) (6)

Modules qui s'alimentent depuis le post courant. Surtout utiles dans
le Theme Builder (templates de pages dynamiques).

| blockName | Statut | Note |
|-----------|--------|------|
| `divi/post-title` | 🟡 | Titre dynamique du post |
| `divi/post-content` | 🟡 | Contenu du post |
| `divi/post-navigation` | 🟡 | Liens précédent / suivant |
| `divi/post-slider` | 🟡 | Articles en curseur |
| `divi/blog` | 🟡 | Liste d'articles paginée |
| `divi/comments` | 🟡 | Commentaires WP |
| `divi/breadcrumbs` | 🟡 | Fil d'Ariane (utile SEO) |

Variantes pleine largeur (`fullwidth-*`) :
- `divi/fullwidth-header`
- `divi/fullwidth-image`
- `divi/fullwidth-map`
- `divi/fullwidth-menu`
- `divi/fullwidth-portfolio`
- `divi/fullwidth-post-content`
- `divi/fullwidth-post-slider`
- `divi/fullwidth-post-title`
- `divi/fullwidth-slider`
- `divi/fullwidth-code`

---

## 8. Navigation et menus (3)

| blockName | Statut | Note |
|-----------|--------|------|
| `divi/menu` | 🟡 | Menu WP |
| `divi/search` | 🟡 | Barre de recherche |
| `divi/sidebar` | 🟡 | Affiche une sidebar WP |

---

## 9. Formulaires et utilisateurs (4)

| blockName | Statut | Builder TS | Note |
|-----------|--------|------------|------|
| `divi/contact-form` (composé) | ✅ | `contactForm({ fields })` | Form natif Divi |
| `divi/contact-form-7` | 🟡 | — | Intégration CF7 |
| `divi/signup` | 🟡 | À ajouter | Email opt-in (newsletter) |
| `divi/signup-custom-field` | 🟡 | — | Enfant de signup |
| `divi/login` | 🟡 | À ajouter | Formulaire de login WP |

---

## 10. Médias et richesse visuelle (4)

| blockName | Statut | Note |
|-----------|--------|------|
| `divi/lottie` | 🟡 | Animations Lottie (JSON) |
| `divi/svg` | 🟡 | SVG inline |
| `divi/icon` | 🟡 | Icône Divi (unicode + couleur + taille) |
| `divi/link` | 🟡 | Bouton/lien stylé |

---

## 11. Mise en page avancée (3)

| blockName | Statut | Note |
|-----------|--------|------|
| `divi/group` | 🟡 | Regroupement de blocs |
| `divi/group-carousel` | 🟡 | Groupe en carrousel |
| `divi/canvas-portal` | 🟡 | Portail vers off-canvas |
| `divi/dropdown` | 🟡 | Menu déroulant |
| `divi/common` | 🟡 | Module utilitaire (rare en usage direct) |

---

## 12. WooCommerce (25 modules) ⚠️

Pour sites e-commerce. **Tous à confirmer côté blockName** — le pattern
est probablement `divi/woocommerce-{slug}` mais à valider en peuplant
une page WooCommerce de référence.

| Module | blockName probable |
|--------|---------------------|
| Breadcrumb | `divi/woocommerce-breadcrumb` |
| CartNotice | `divi/woocommerce-cart-notice` |
| CartProducts | `divi/woocommerce-cart-products` |
| CartTotals | `divi/woocommerce-cart-totals` |
| CheckoutBilling | `divi/woocommerce-checkout-billing` |
| CheckoutInformation | `divi/woocommerce-checkout-information` |
| CheckoutOrderDetails | `divi/woocommerce-checkout-order-details` |
| CheckoutPaymentInfo | `divi/woocommerce-checkout-payment-info` |
| CheckoutShipping | `divi/woocommerce-checkout-shipping` |
| CrossSells | `divi/woocommerce-cross-sells` |
| ProductAdditionalInfo | `divi/woocommerce-product-additional-info` |
| ProductAddToCart | `divi/woocommerce-product-add-to-cart` |
| ProductDescription | `divi/woocommerce-product-description` |
| ProductGallery | `divi/woocommerce-product-gallery` |
| ProductImages | `divi/woocommerce-product-images` |
| ProductMeta | `divi/woocommerce-product-meta` |
| ProductPrice | `divi/woocommerce-product-price` |
| ProductRating | `divi/woocommerce-product-rating` |
| ProductReviews | `divi/woocommerce-product-reviews` |
| Products | `divi/woocommerce-products` |
| ProductStock | `divi/woocommerce-product-stock` |
| ProductTabs | `divi/woocommerce-product-tabs` |
| ProductTitle | `divi/woocommerce-product-title` |
| ProductUpsell | `divi/woocommerce-product-upsell` |
| RelatedProducts | `divi/woocommerce-related-products` |

→ **Phase ultérieure** : créer une page produit WooCommerce de référence
pour valider tous ces blockNames et leur structure d'attributs.

---

## Synthèse de couverture

| Catégorie | Total | ✅ Couvert | 🟡 Inventorié | ⚠️ Woo |
|-----------|-------|-----------|---------------|--------|
| Structurels | 6 | 4 | 2 | — |
| Contenu base | 12 | 9 | 3 | — |
| Composés | 12 | 4 | 8 | — |
| Chiffres | 4 | 1 | 3 | — |
| Personnes/témoignages | 2 | 1 | 1 | — |
| Portfolio | 5 | 1 | 4 | — |
| Theme Builder | 6 + 10 fullwidth | 0 | 16 | — |
| Navigation | 3 | 0 | 3 | — |
| Forms/users | 5 | 1 | 4 | — |
| Médias | 4 | 0 | 4 | — |
| Mise en page | 5 | 0 | 5 | — |
| WooCommerce | 25 | 0 | — | 25 |
| **TOTAL natif** | **~99** | **21** | **53** | **25** |

→ **21 modules opérationnels** côté builders TS (couvre toutes les pages
de marketing classique : landing, services, à propos, contact, FAQ).
→ **~30 modules** à ajouter en priorité moyenne (theme builder, portfolio,
icon-list, social-follow, pricing-tables, team, signup).
→ **WooCommerce** : phase dédiée à venir.

---

## Priorités d'extension de `lib/divi/builders.ts`

Top 10 modules à implémenter en priorité (impact / fréquence d'usage) :

1. **`divider`** — séparation visuelle (très utilisé)
2. **`icon`** — icône standalone
3. **`pricing-tables`** + `pricing-tables-item` — pages tarifs
4. **`icon-list`** + `icon-list-item` — listes bullet
5. **`social-media-follow`** + `social-media-follow-item` — footer/header
6. **`team-member`** — page équipe
7. **`signup`** — capture email newsletter
8. **`map`** — page contact
9. **`circle-counter`** + **`bar-counters`** — variations KPIs
10. **`toggle`** — révélation de contenu (mini-accordion)
