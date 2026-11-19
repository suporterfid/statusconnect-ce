---
name: StatusConnect public site
colors:
  canvas: "#FFFFFF"
  surface-warm: "#F7F6F3"
  ink: "#252525"
  ink-secondary: "#5F5F5F"
  border: "#D9D7D3"
  station-sign: "#252525"
  action-blue: "#1A6DC1"
  status-ok-jade: "#059669"
  status-slow-gold: "#D97706"
  status-down-crimson: "#B91C1C"
typography:
  ui:
    fontFamily: "Inter, Noto Sans, Arial, sans-serif"
  display:
    fontSize: 44px
    fontWeight: 700
    letterSpacing: -0.025em
  section:
    fontSize: 32px
    fontWeight: 700
  body:
    fontSize: 16px
    lineHeight: 1.5
rounded:
  control: 4px
  card: 6px
  pill: 999px
spacing:
  scale: [4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 72]
---

# Design System: StatusConnect public site

Applies to the landing pages (`/`, `/en`), the account pages (`/cadastro` and password pages), the legal pages and the public status pages (`/status/{slug}` and custom domains). Tokens live in `public/css/site.css`, `public/css/status.css` for status pages, and `frontend/src/assets/main.css` for the operator app (`/app`). HTML emails use `resources/views/mail/layout.blade.php`.

## Overview

This is the "Situação das linhas" board of São Paulo's Metrô, built inside the IROH Initiative tokens (`../iroh/BRANDING.md`). Every monitored service is a line. The page reads like a station: charcoal sign bands with white lettering, a warm neutral canvas, ruled rows, and route diagrams drawn as metro lines with numbered stations. The tone is calm and plain-spoken. It refuses the dark SaaS hero with glowing green dots.

## Colors

Strategy: restrained. The canvas is warm neutral, charcoal carries the structure, one blue is the action, and three status colours are used only for status.

### Primary
- **Station-sign charcoal** (`#252525`; `#0F0F0F` in dark mode): the header, footer, board head and plan heads, always with white lettering.
- **Action blue** (`#1A6DC1`; `#529CCA` in dark mode): primary buttons only.

### Neutral
- **Canvas:** `#FFFFFF` (dark `#191919`).
- **Warm surface:** `#F7F6F3` for the hero and the legal page head.
- **Lines:** borders `#D9D7D3`. `--line-ink` draws route lines on the canvas: charcoal in light mode, `#C6C6C2` in dark mode.

### Named Rules
- **Status takes colour, and shape too.** Jade solid roundel means "Operação normal". Gold half roundel means "Velocidade reduzida". Crimson struck ring means "Paralisada". A blue ring means "checking". Never use a status colour as decoration.
- **Identity by number, never by colour.** Lines and features get numbered charcoal roundels (`.line-no`).

## Typography

Inter is self-hosted (`public/fonts/inter-latin-var.woff2`, OFL) and is the only family. The IROH brand pins it. The impeccable detector flags it as overused; that warning is accepted on brand grounds.

### Hierarchy
- **Display:** 32–44px, 700, -0.025em. Hero only.
- **Section title:** 24–32px, 700.
- **Card and step titles:** 17–20px, 600–700.
- **Body:** 16–18px. Secondary text uses `--color-text-secondary`.
- **Numbers:** tabular figures for prices, limits and line numbers.

### Named Rules
- **Hierarchy by size and weight in one family.** No kicker or eyebrow labels above headings.

## Layout

- **Width and gutters:** max width 1200px; gutters 16, 20 and 32px. Reading measure is 720px (legal pages).
- **Hero:** two columns from 1024px (offer, then board). The email field works and carries the email into `/cadastro?email=`.
- **Section rhythm:** 72px between sections.
- **Route diagrams:** vertical below 900px, horizontal above.
- **Plans:** subgrid so rows align across the three plans.

## Elevation & Depth

Flat by default. The board and the featured plan use `0 4px 12px rgb(0 0 0 / .14)`. Cards rest on a 1px border.

## Shapes

- **Radii:** 4px for controls, 6px for cards and boards, 999px for tags.
- **Circles:** stations and roundels are always circles. Route lines are 4px.

## Components

### Buttons
- **Primary:** blue fill.
- **Quiet:** inset 1px strong border on the canvas.
- **On charcoal bands:** a white fill.
- **All buttons:** at least 44px tall; colour transitions take 120ms.

### Board
A sign-band head with a title and an "Exemplo" tag, then ruled rows: line number, name with its kind, and the status. Line 5 cycles through Paralisada, then "Confirmando em São Paulo…" (only while second-location confirmation is on), then Operação normal, on a 12s loop. Under reduced motion it shows only the final state.

### Route
A numbered station sequence on a 4px line. The current step is a filled station. The second-location step is a jade station.

### Status pages
- **Header:** the station sign, replaced by the tenant's accent colour and logo when set. The tenant's brand comes first; StatusConnect appears only as the small "Status page by" mark, and not at all on white-label pages.
- **Overall state:** a banner on the warm surface under the sign, with a 32px roundel and the state sentence.
- **Components:** numbered lines on a board, each with its status label (roundel plus text) and the 90-day bars. Days without data use the border colour.
- **Response time:** drawn in `--line-ink`, never a status colour.
- **Calendar:** each day is tinted with its state and has a 4px status bar; outage days also get a ring.
- **Favicon:** the tenant logo when present, otherwise the StatusConnect favicon, and none on white-label pages.

### Operator app
- **Sidebar:** the station sign, with the StatusConnect mark. Navigation is drawn as a metro line: each item is a station, and the current page is the filled station.
- **Canvas:** the warm neutral surface, with white cards on 1px borders. Light and dark themes follow `prefers-color-scheme`.
- **Status badges:** a roundel plus body-coloured text, using the same shapes as the site.
- **Charts:** drawn in `--line-ink`.
- **Icons:** no emoji icons; actions are labelled in words.

### Error pages
- **Views:** `resources/views/errors/`. The 4xx and 5xx fallbacks cover statuses without their own view.
- **Layout:** a station-sign header and footer, then a short route whose line breaks off at a status roundel: gold half for client errors, crimson struck ring for server errors.
- **Language:** pt-BR, or English for `/en` paths and browsers that prefer English.
- **Robustness:** the view reads no database or session.

## Brand assets

`assets/brand/` holds:
- `mark.svg`: an S drawn as a metro line between two stations, the two check locations, with the destination station in jade.
- `mark-monochrome.svg`: uses `currentColor`.
- `favicon.svg`: the mark in white on a charcoal tile.
- `wordmark.svg`: uses live Inter text.

Published copies are in `public/brand/`, `public/favicon.svg` and `public/apple-touch-icon.png`. The PNGs are rasterised from the SVGs, and their origin is embedded in each file.
