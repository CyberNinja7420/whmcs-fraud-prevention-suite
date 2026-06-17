# FPS Typography Panel — Design Document
**Date:** 2026-04-07
**Status:** Approved, ready for implementation
**Replaces:** Simple 7-slider font-size section (introduced 2026-04-07)

---

## Problem

The current Display Settings card has 7 independent range sliders that control font **size only** per section. This is too limited — a real typography system needs font family, weight, letter-spacing, and line-height as well.

---

## Goal

A full per-section typography panel: 7 sections × 5 controls each, with system and Google fonts, live preview, and the same "emit nothing if default" CSS injection pattern already used for colors.

---

## Section 1 — Data Model

**7 new rows in `mod_fps_settings`:**

| `setting_key`      | Section           |
|--------------------|-------------------|
| `typo_tabs`        | Tab Labels        |
| `typo_stats`       | Stat Card Numbers |
| `typo_stat_labels` | Stat Card Labels  |
| `typo_table_header`| Table Headers     |
| `typo_table_body`  | Table Body        |
| `typo_card_header` | Card Headers      |
| `typo_card_body`   | Card Body Text    |

Each `setting_value` is a JSON string:
```json
{
  "family":        "system",
  "weight":        "400",
  "size":          "0.84",
  "letterSpacing": "0.01",
  "lineHeight":    "1.5"
}
```

- `family` is a **token** (e.g. `"inter"`, `"poppins"`, `"mono"`) resolved to a full font stack by PHP. Never raw CSS stored in DB.
- `weight` is one of `"300"` (Light), `"400"` (Regular), `"700"` (Bold).
- `size` is a unitless float string; `rem` appended at CSS injection time.
- `letterSpacing` is a unitless float string; `em` appended at injection time.
- `lineHeight` is a unitless float string; no unit (CSS accepts unitless line-height).

**Migration:** Existing `font_size_*` rows stay in DB for backward compatibility but are superseded. The `typo_*` rows take precedence. Old simple sliders UI is replaced by the new panel.

---

## Section 2 — CSS Variables

35 CSS variables total (5 per section). Naming pattern: `--fps-{property}-{section}`.

### Variables per section (example: tabs)
```css
--fps-font-tabs:     system-ui, -apple-system, 'Segoe UI', sans-serif;
--fps-weight-tabs:   600;
--fps-size-tabs:     0.84rem;
--fps-tracking-tabs: 0.01em;
--fps-lh-tabs:       1.4;
```

### Target selectors in `fps-1000x.css`
```css
.fps-tab-btn {
  font-family:    var(--fps-font-tabs,     system-ui, sans-serif);
  font-weight:    var(--fps-weight-tabs,   600);
  font-size:      var(--fps-size-tabs,     0.84rem);
  letter-spacing: var(--fps-tracking-tabs, 0.01em);
  line-height:    var(--fps-lh-tabs,       1.4);
}
/* same pattern for .fps-stat-value, .fps-stat-label,
   .fps-table thead th, .fps-table, .fps-card-header h2/h3/h4, .fps-card-body */
```

Fallback values match today's hardcoded values exactly — zero visual change if no `typo_*` row exists.

### Default values per section

| Section key    | family | weight | size   | letterSpacing | lineHeight |
|----------------|--------|--------|--------|---------------|------------|
| tabs           | system | 600    | 0.84   | 0.01          | 1.4        |
| stats          | system | 700    | 1.80   | -0.02         | 1.2        |
| stat_labels    | system | 500    | 0.85   | 0.06          | 1.4        |
| table_header   | system | 600    | 0.80   | 0.07          | 1.4        |
| table_body     | system | 400    | 0.90   | 0.00          | 1.5        |
| card_header    | system | 600    | 1.10   | 0.01          | 1.3        |
| card_body      | system | 400    | 0.95   | 0.00          | 1.6        |

### PHP injection rule
Only emit CSS vars that differ from the section default. If all 7 sections are at defaults, nothing is injected — no `<style>` tag emitted at all.

---

## Section 3 — UI Panel Layout

Replaces the "Per-Section Font Sizes" card in Display Settings.

```
┌─ Typography ──────────────────────────────────────────────────────┐
│ [Tab Labels] [Stat Numbers] [Stat Labels] [Table Headers]         │
│ [Table Body] [Card Headers] [Card Body]                           │
├───────────────────────────────────────────────────────────────────┤
│ Font Family                                                        │
│ ┌─────────────────────────────────────────────┐                   │
│ │ ── System Fonts ──────────────────────────  │                   │
│ │   System Default · Georgia · Monospace      │                   │
│ │   Arial · Inter (if installed)              │                   │
│ │ ── Google Fonts (loads on select) ────────  │                   │
│ │   Inter · Roboto · Poppins · Open Sans      │                   │
│ │   Lato · Nunito · Merriweather              │                   │
│ │   Playfair Display · JetBrains Mono         │                   │
│ └─────────────────────────────────────────────┘                   │
│                                                                    │
│ Weight         [  Light  ] [ Regular ] [  Bold  ]                 │
│                                                                    │
│ Size           ●━━━━━━━━━  0.84rem    [0.60 ─── 2.00]            │
│                                                                    │
│ Letter Spacing ●━━━━━━━━━  0.01em    [-0.05 ─── 0.20]            │
│                                                                    │
│ Line Height    ●━━━━━━━━━  1.4        [1.0 ──── 2.5]             │
│                                                                    │
│ Preview: The quick brown fox jumps — 0123456789                    │
│                                                                    │
│      [ Reset This Section ]   [ Reset All Typography ]            │
└───────────────────────────────────────────────────────────────────┘
```

**UI details:**
- Mini-tabs are pill-style, smaller than main FPS nav tabs
- Live preview line updates on every control change with all 5 properties applied simultaneously
- Google Fonts optgroup items show `(loading…)` label in preview until font arrives
- `Reset This Section` reverts active section only
- `Reset All Typography` reverts all 7 sections
- Hidden inputs `name="typo_tabs"` etc. hold the current JSON; updated on every change so the save bar collects them automatically

### Font token map

| Token         | Font stack                                          | Google Fonts URL param              |
|---------------|-----------------------------------------------------|-------------------------------------|
| `system`      | `system-ui,-apple-system,'Segoe UI',sans-serif`     | null (no load)                      |
| `georgia`     | `Georgia,'Times New Roman',serif`                   | null                                |
| `mono`        | `'JetBrains Mono','Fira Code',Consolas,monospace`   | null                                |
| `arial`       | `Arial,Helvetica,sans-serif`                        | null                                |
| `inter-sys`   | `Inter,system-ui,sans-serif`                        | null                                |
| `inter`       | `Inter,system-ui,sans-serif`                        | `Inter:wght@300;400;700`            |
| `roboto`      | `Roboto,system-ui,sans-serif`                       | `Roboto:wght@300;400;700`           |
| `poppins`     | `Poppins,system-ui,sans-serif`                      | `Poppins:wght@300;400;700`          |
| `opensans`    | `'Open Sans',system-ui,sans-serif`                  | `Open+Sans:wght@300;400;700`        |
| `lato`        | `Lato,system-ui,sans-serif`                         | `Lato:wght@300;400;700`             |
| `nunito`      | `Nunito,system-ui,sans-serif`                       | `Nunito:wght@300;400;700`           |
| `merriweather`| `Merriweather,Georgia,serif`                        | `Merriweather:wght@300;400;700`     |
| `playfair`    | `'Playfair Display',Georgia,serif`                  | `Playfair+Display:wght@400;700`     |
| `jetbrains`   | `'JetBrains Mono',Consolas,monospace`               | `JetBrains+Mono:wght@300;400;700`   |

---

## Section 4 — JavaScript

Four new functions on the `FpsAdmin` namespace in `fps-admin.js`:

### `FpsAdmin._typo` (state object)
```javascript
FpsAdmin._typo = {
  tabs:         { family: 'system', weight: '600', size: '0.84', letterSpacing: '0.01', lineHeight: '1.4' },
  stats:        { family: 'system', weight: '700', size: '1.80', letterSpacing: '-0.02', lineHeight: '1.2' },
  stat_labels:  { ... },
  table_header: { ... },
  table_body:   { ... },
  card_header:  { ... },
  card_body:    { ... },
};
```
Populated from PHP-rendered hidden fields on page load. Active section key tracked in `FpsAdmin._typoActive`.

### `FpsAdmin.switchTypoSection(sectionKey)`
- Updates mini-tab active state
- Populates all 5 controls from `_typo[sectionKey]`
- Updates live preview

### `FpsAdmin.previewTypo(sectionKey, property, value)`
- Updates `_typo[sectionKey][property]`
- Updates hidden input JSON
- For `family` changes on Google Fonts: calls `loadGoogleFont(token)` first, applies CSS var in callback
- For all other changes: sets CSS var on `.fps-module-wrapper` immediately
- Updates live preview

### `FpsAdmin.loadGoogleFont(token)`
- If `document.querySelector('link[data-fps-font="' + token + '"]')` exists → resolves immediately
- Otherwise injects `<link rel="stylesheet" data-fps-font="token" href="https://fonts.googleapis.com/css2?family=...&display=swap">`
- Resolves when `onload` fires (timeout 800ms — never blocks UI)

### `FpsAdmin.serializeTypoSection(sectionKey)`
- Reads 5 control values from DOM
- Returns JSON string
- Called by `previewTypo` to keep hidden field in sync

---

## Section 5 — PHP Changes

### `lib/Admin/TabSettings.php`
- `fpsRenderDisplaySettings()` calls new `fpsRenderTypographyPanel($config)` in place of the `$fontSizeHtml` block
- `fpsRenderTypographyPanel()` is a new private method that:
  - Reads all 7 `typo_*` keys via `$config->getCustom()`
  - Decodes and validates each JSON (falls back to defaults if malformed)
  - Builds the mini-tabs, 5 controls, preview line, hidden fields
  - Uses a PHP-side `$fontTokens` array to resolve family tokens to `<option selected>` markup

### `fraud_prevention_suite.php`

**Seeding (in `fraud_prevention_suite_activate()`):**
```php
'typo_tabs'         => '{"family":"system","weight":"600","size":"0.84","letterSpacing":"0.01","lineHeight":"1.4"}',
'typo_stats'        => '{"family":"system","weight":"700","size":"1.80","letterSpacing":"-0.02","lineHeight":"1.2"}',
'typo_stat_labels'  => '{"family":"system","weight":"500","size":"0.85","letterSpacing":"0.06","lineHeight":"1.4"}',
'typo_table_header' => '{"family":"system","weight":"600","size":"0.80","letterSpacing":"0.07","lineHeight":"1.4"}',
'typo_table_body'   => '{"family":"system","weight":"400","size":"0.90","letterSpacing":"0.00","lineHeight":"1.5"}',
'typo_card_header'  => '{"family":"system","weight":"600","size":"1.10","letterSpacing":"0.01","lineHeight":"1.3"}',
'typo_card_body'    => '{"family":"system","weight":"400","size":"0.95","letterSpacing":"0.00","lineHeight":"1.6"}',
```

**`_output()` injection block (after color injection):**
1. Read 7 `typo_*` keys in single `whereIn` query
2. For each: decode JSON, validate each of 5 values
3. Collect Google Font tokens that need loading → emit deduplicated `<link>` tags
4. Build CSS var string for non-default values only → emit `<style>` block if non-empty

### Validation rules in PHP
- `family`: must exist in `$fontTokenMap` keys — else fallback to `system`
- `weight`: must be one of `300`, `400`, `700` — else fallback to section default
- `size`: numeric, 0.60–2.00 — else fallback
- `letterSpacing`: numeric, -0.05–0.20 — else fallback
- `lineHeight`: numeric, 1.0–2.5 — else fallback

---

## Files Changed

| File | Change |
|---|---|
| `assets/css/fps-1000x.css` | Add 35 CSS vars to `:root`; add 5 properties to 7 target selectors |
| `assets/js/fps-admin.js` | Add `_typo` state, `switchTypoSection`, `previewTypo`, `loadGoogleFont`, `serializeTypoSection` |
| `lib/Admin/TabSettings.php` | Replace `$fontSizeHtml` block with `fpsRenderTypographyPanel()` method |
| `fraud_prevention_suite.php` | Seed 7 `typo_*` defaults; add CSS + Google Font injection in `_output()` |

---

## Testing Plan

PHP CLI test script covering:
1. All 7 `typo_*` keys seeded with valid JSON
2. Each JSON decodes and validates correctly
3. Settings tab renders all 5 controls + mini-tabs
4. Non-default family (Google Font) → correct `<link>` tag emitted
5. Non-default weight → correct `--fps-weight-*` var emitted
6. Non-default size → correct `--fps-size-*` var emitted
7. All-default section → no CSS vars emitted for that section
8. Malformed JSON in DB → graceful fallback to defaults, no fatal error
9. All 14 tabs still render clean after changes
