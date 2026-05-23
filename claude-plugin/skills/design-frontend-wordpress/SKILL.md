---
name: design-frontend-wordpress
description: Produire des pages WordPress visuellement attrayantes, lisibles et responsives. À utiliser pour concevoir ou améliorer le design d'une page (hiérarchie visuelle, typographie, couleurs, espacement, responsive, accessibilité). Pertinent en complément de creer-page-wordpress et notamment pour les pages Divi 5.
---

# Design frontend WordPress — méthode

Le design n'est pas une décoration finale : c'est ce qui **donne sens à la
lecture** d'une page. Une page bien conçue se lit dans un ordre clair,
hiérarchise les informations, et reste utilisable sur mobile comme sur
desktop. Ce skill rassemble les principes que tu dois appliquer
systématiquement quand tu produis ou améliores une page WordPress (Divi 5
en particulier — voir aussi `creer-page-wordpress`).

## Les sept principes fondamentaux

### 1. Hiérarchie visuelle

Le visiteur scanne avant de lire. Quand il regarde la page deux secondes,
il doit comprendre :
- **Le sujet** (titre principal, grosse taille, fort contraste).
- **La promesse** (sous-titre / sous-headline).
- **L'action** attendue (CTA visible sans scroller — au-dessus de la ligne
  de flottaison).

Outils : tailles de police nettement différenciées (au moins 1,4×
d'écart entre H1 et corps), poids typographique, couleur, espacement.

### 2. Contraste (lisibilité + accessibilité)

- **Ratio de contraste WCAG AA minimum** : 4,5:1 pour du texte courant,
  3:1 pour du texte ≥ 18 pt ou en gras.
- **Pas de gris pâle sur blanc** pour du contenu : c'est joli en mockup,
  illisible en pratique.
- **Couleurs des liens** : doivent se distinguer du texte (au-delà du
  simple soulignement).
- Tester avec un outil de contraste (WebAIM Contrast Checker mentalement
  ou via Rank Math / browser devtools).

### 3. Typographie

- **Maximum 2 familles** de polices par page (souvent : 1 sans-serif
  pour les titres + 1 serif ou sans-serif pour le corps).
- **Tailles** : 16 px minimum pour le corps sur desktop, 18 px idéal
  pour le confort de lecture.
- **Interlignage** : 1,4 à 1,6 × la taille de police pour le corps.
  Resserré (1,1–1,2) pour les titres.
- **Longueur de ligne** : 60 à 80 caractères. Au-delà, l'œil se perd —
  utiliser une `max-width` sur les blocs texte.
- **Justification** : alignée à gauche (ragged-right). Le justifié
  produit des rivières blanches inesthétiques en l'absence de
  césure automatique propre.

### 4. Espacement (le vrai héros)

- **Marge intérieure (padding)** des sections : généreuse — 80 à 120 px
  vertical sur desktop, 40 à 60 px sur mobile.
- **Espace entre blocs** : laisser respirer. Une page chargée à 100 %
  fatigue.
- **Règle de 8** : utiliser un système d'espacement par multiples de 8
  px (8, 16, 24, 32, 48, 64, 96…) pour la cohérence.
- **White space ≠ vide** : c'est ce qui donne de la valeur aux éléments
  conservés.

### 5. Mobile-first

- **Vérifier sur 360 px de large** : c'est le smartphone le plus
  courant. Si la page passe à 360, elle passe partout.
- **Tester le scroll** : au mobile, on scrolle. Optimiser pour la
  verticalité (cards empilées plutôt que côte à côte).
- **CTA accessibles au pouce** : positionnés dans la zone basse de
  l'écran ou pleine largeur, hauteur 44 px minimum (cible tactile).
- **Pas de hover seul** pour révéler une information : impossible au
  tactile.
- **Images responsives** : `srcset`, formats modernes (WebP, AVIF), pas
  d'image > 1500 px de large sans bonne raison.

### 6. Cohérence (design system)

Sur un site Divi : exploiter les **global colors**, **global fonts**,
**presets** Divi 5 (routes `divi/v1/global-data/*`). Si l'utilisateur n'a
pas de design system, **en proposer un** dès la première page :

- **Couleurs** : 1 primaire, 1 secondaire (accent), 1 neutre foncé (texte),
  1 neutre clair (fond). 4 à 5 couleurs maximum sur tout le site.
- **Échelle typographique** : 5 à 6 tailles (ex. 12, 14, 16, 20, 28, 40,
  64 px) cohérentes site-wide.
- **Composants réutilisables** : boutons, cards, badges, formulaires —
  tous au même style.

### 7. Animations et interactions (avec retenue)

- **Apparitions au scroll** : oui, mais douces (200–400 ms, opacity +
  léger translateY). Pas d'effet « spectacle ».
- **Hover** : effet subtil (élévation, légère teinte) pour signaler
  l'interactivité.
- **Pas d'auto-play vidéo avec son** — jamais.
- **Préférer `prefers-reduced-motion`** : respecter le réglage
  utilisateur.

## Spécificités Divi 5

Quand tu construis avec Divi 5 :

1. **Pense en sections > rows > modules**. Une page = 4 à 8 sections.
   Chaque section a UN objectif (présenter, convaincre, rassurer, agir).
2. **Utilise les global colors** plutôt que des couleurs hardcodées :
   un changement de charte se propage automatiquement.
3. **Multi-breakpoints** : Divi 5 expose mobile/tablette/desktop (plus
   des breakpoints custom). Toujours **vérifier** les trois — la même
   marge en desktop est trop grande en mobile.
4. **Modules les plus utiles** :
   - `Text` — texte riche
   - `Image` — image avec lien et alt
   - `Blurb` — icône + titre + texte (parfait pour features)
   - `Call To Action` — bloc CTA prêt à l'emploi
   - `Button` — bouton réutilisable
   - `Number Counter` — chiffres clés (avec animation)
   - `Testimonial` — citation client
   - `Gallery` — grille d'images
5. **Évite le module Code** sauf nécessité absolue : il contourne la
   logique du builder et complique la maintenance.

## Workflow design

Quand on te demande de créer ou améliorer une page :

1. **Comprendre l'audience et l'objectif** (avant de penser couleurs ou
   polices). Demande à l'utilisateur :
   - Qui est le visiteur cible ?
   - Quelle est l'action attendue après lecture ?
   - Quel ton (formel, chaleureux, premium, …) ?
2. **Proposer une structure** sous forme d'arborescence de sections
   avant de toucher au visuel. Faire valider.
3. **Définir le design system** si absent (couleurs, polices, échelle).
4. **Construire section par section**, en testant à chaque étape sur
   desktop **et** mobile (l'utilisateur peut visualiser au fil de l'eau).
5. **Audit final** : repasser la checklist des 7 principes, faire valider.

## Anti-patterns à signaler

Quand tu détectes ces erreurs sur une page existante, **propose de les
corriger** (sans modifier sans accord) :

- Texte gris pâle sur fond blanc.
- Plus de 3 polices différentes.
- CTA mêlé visuellement au reste (pas de différenciation).
- Sections collées les unes aux autres (pas d'espace).
- Images étirées ou compressées.
- Carrousels auto-play sans pause.
- Pop-ups intempestifs au chargement.
- H1 absent ou multiple.
- Mobile non testé (texte qui dépasse, boutons illisibles).
- Page « tout en un » sans hiérarchie (un mur de texte).
