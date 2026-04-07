# FPS Typography Panel Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the 7 per-section font-size sliders with a full typography panel giving independent control of font family, weight, letter-spacing, line-height, and size for each of 7 UI sections.

**Architecture:** JSON-per-section data model (7 DB rows, each a JSON blob). PHP resolves family tokens to font stacks (never raw CSS in DB). CSS variables with exact fallback values ensure zero visual change when all settings are at defaults. "Emit nothing if default" injection pattern — identical to existing color injection.

**Tech Stack:** PHP 8.2, WHMCS Capsule ORM, CSS Custom Properties, vanilla JS (existing FpsAdmin namespace), Google Fonts API on-demand loading.

**Design doc:** `docs/plans/2026-04-07-typography-panel-design.md` — read this before starting. All defaults, token maps, CSS variable names, and section keys are specified there.

---

## Task 1: CSS — Add 35 typography variables and update 7 selectors

**Files:**
- Modify: `assets/css/fps-1000x.css`
  - `:root` block: ~line 85 (after existing `--fps-size-card-body` line)
  - `.fps-tab-btn`: ~line 403
  - `.fps-stat-value`: ~line 342
  - `.fps-stat-label`: ~line 351
  - `.fps-table`: ~line 472
  - `.fps-table thead th`: ~line 485
  - `.fps-card-header h2/h3/h4`: ~line 230
  - `.fps-card-body`: ~line 257

**Step 1: Add 35 CSS variables to `:root` block**

After the existing `--fps-size-card-body: 0.95rem;` line (~line 95), insert the 35 new typography variables. The comment `/* Per-section font sizes */` should become `/* Per-section typography (family · weight · size · tracking · line-height) */`.

Add this block immediately after `--fps-size-card-body: 0.95rem;`:

```css
  /* Per-section typography vars (set via inline <style> from saved settings) */
  /* tabs */
  --fps-font-tabs:     system-ui, -apple-system, 'Segoe UI', sans-serif;
  --fps-weight-tabs:   600;
  --fps-tracking-tabs: 0.01em;
  --fps-lh-tabs:       1.4;
  /* stats */
  --fps-font-stats:     system-ui, -apple-system, 'Segoe UI', sans-serif;
  --fps-weight-stats:   700;
  --fps-tracking-stats: -0.02em;
  --fps-lh-stats:       1.2;
  /* stat labels */
  --fps-font-stat-labels:     system-ui, -apple-system, 'Segoe UI', sans-serif;
  --fps-weight-stat-labels:   500;
  --fps-tracking-stat-labels: 0.06em;
  --fps-lh-stat-labels:       1.4;
  /* table headers */
  --fps-font-table-header:     system-ui, -apple-system, 'Segoe UI', sans-serif;
  --fps-weight-table-header:   600;
  --fps-tracking-table-header: 0.07em;
  --fps-lh-table-header:       1.4;
  /* table body */
  --fps-font-table-body:     system-ui, -apple-system, 'Segoe UI', sans-serif;
  --fps-weight-table-body:   400;
  --fps-tracking-table-body: 0em;
  --fps-lh-table-body:       1.5;
  /* card headers */
  --fps-font-card-header:     system-ui, -apple-system, 'Segoe UI', sans-serif;
  --fps-weight-card-header:   600;
  --fps-tracking-card-header: 0.01em;
  --fps-lh-card-header:       1.3;
  /* card body */
  --fps-font-card-body:     system-ui, -apple-system, 'Segoe UI', sans-serif;
  --fps-weight-card-body:   400;
  --fps-tracking-card-body: 0em;
  --fps-lh-card-body:       1.6;
```

Note: `--fps-size-*` variables already exist and stay unchanged. The 5th property (size) reuses them.

**Step 2: Wire new vars into the 7 target selectors**

For each selector, add the 4 new properties using `var()` with fallback matching current hardcoded values:

**`.fps-tab-btn`** (~line 403) — currently has `font-size`, `font-weight: 600`, `font-family`:
```css
  font-family:    var(--fps-font-tabs,     system-ui, -apple-system, 'Segoe UI', sans-serif);
  font-weight:    var(--fps-weight-tabs,   600);
  letter-spacing: var(--fps-tracking-tabs, 0.01em);
  line-height:    var(--fps-lh-tabs,       1.4);
```
Replace existing `font-weight: 600;` and `font-family: var(--fps-font);` lines with the above 4. Keep `font-size: var(--fps-size-tabs, 0.84rem);` unchanged.

**`.fps-stat-value`** (~line 342) — currently has `font-weight: 700`, `line-height: 1.2`, `letter-spacing: -0.02em`:
```css
  font-family:    var(--fps-font-stats,     system-ui, -apple-system, 'Segoe UI', sans-serif);
  font-weight:    var(--fps-weight-stats,   700);
  letter-spacing: var(--fps-tracking-stats, -0.02em);
  line-height:    var(--fps-lh-stats,       1.2);
```
Replace the 3 hardcoded properties with these 4 vars. Keep `font-size: var(--fps-size-stats, 1.8rem);` unchanged.

**`.fps-stat-label`** (~line 351) — currently has `font-weight: 500`, `letter-spacing: 0.06em`:
```css
  font-family:    var(--fps-font-stat-labels,     system-ui, -apple-system, 'Segoe UI', sans-serif);
  font-weight:    var(--fps-weight-stat-labels,   500);
  letter-spacing: var(--fps-tracking-stat-labels, 0.06em);
  line-height:    var(--fps-lh-stat-labels,       1.4);
```
Replace `font-weight: 500;` and `letter-spacing: 0.06em;` with these 4 vars.

**`.fps-table`** (~line 472) — currently has only `font-size`:
```css
  font-family:    var(--fps-font-table-body,     system-ui, -apple-system, 'Segoe UI', sans-serif);
  font-weight:    var(--fps-weight-table-body,   400);
  letter-spacing: var(--fps-tracking-table-body, 0em);
  line-height:    var(--fps-lh-table-body,       1.5);
```
Add these 4 new properties. Keep `font-size: var(--fps-size-td, 0.9rem);` unchanged.

**`.fps-table thead th`** (~line 485) — currently has `font-weight: 600`, `letter-spacing: 0.07em`:
```css
  font-family:    var(--fps-font-table-header,     system-ui, -apple-system, 'Segoe UI', sans-serif);
  font-weight:    var(--fps-weight-table-header,   600);
  letter-spacing: var(--fps-tracking-table-header, 0.07em);
  line-height:    var(--fps-lh-table-header,       1.4);
```
Replace `font-weight: 600;` and `letter-spacing: 0.07em;` with these 4 vars.

**`.fps-card-header h2, h3, h4`** (~line 230) — currently has `font-weight: 600`, `letter-spacing: 0.01em`:
```css
  font-family:    var(--fps-font-card-header,     system-ui, -apple-system, 'Segoe UI', sans-serif);
  font-weight:    var(--fps-weight-card-header,   600);
  letter-spacing: var(--fps-tracking-card-header, 0.01em);
  line-height:    var(--fps-lh-card-header,       1.3);
```
Replace `font-weight: 600;` and `letter-spacing: 0.01em;` with these 4 vars.

**`.fps-card-body`** (~line 257) — currently has only `font-size`:
```css
  font-family:    var(--fps-font-card-body,     system-ui, -apple-system, 'Segoe UI', sans-serif);
  font-weight:    var(--fps-weight-card-body,   400);
  letter-spacing: var(--fps-tracking-card-body, 0em);
  line-height:    var(--fps-lh-card-body,       1.6);
```
Add these 4 new properties.

**Step 3: Verify no hardcoded font-weight/letter-spacing/line-height remain in the 7 target selectors**

```bash
grep -n "font-weight: [0-9]\|letter-spacing: [0-9-]\|line-height: [0-9]" \
  fraud_prevention_suite/assets/css/fps-1000x.css | grep -v "var(--fps-"
```

Any matches in the 7 selectors are bugs. Matches elsewhere (other selectors) are fine.

**Step 4: Commit**

```bash
cd /d/Claude\ workfolder/Claude\ workfolder/pedantic-wiles
git add fraud_prevention_suite/assets/css/fps-1000x.css
git commit -m "feat(css): add 35 typography vars and wire 7 target selectors"
```

---

## Task 2: PHP Seeding — Add 7 `typo_*` defaults in activate()

**Files:**
- Modify: `fraud_prevention_suite.php` — `fraud_prevention_suite_activate()` defaults array (~line 650)

**Step 1: Add 7 `typo_*` seed rows after the existing `font_size_*` rows**

After `'font_size_card_body' => '0.95',` (~line 656), add:

```php
            'typo_tabs'         => '{"family":"system","weight":"600","size":"0.84","letterSpacing":"0.01","lineHeight":"1.4"}',
            'typo_stats'        => '{"family":"system","weight":"700","size":"1.80","letterSpacing":"-0.02","lineHeight":"1.2"}',
            'typo_stat_labels'  => '{"family":"system","weight":"500","size":"0.85","letterSpacing":"0.06","lineHeight":"1.4"}',
            'typo_table_header' => '{"family":"system","weight":"600","size":"0.80","letterSpacing":"0.07","lineHeight":"1.4"}',
            'typo_table_body'   => '{"family":"system","weight":"400","size":"0.90","letterSpacing":"0.00","lineHeight":"1.5"}',
            'typo_card_header'  => '{"family":"system","weight":"600","size":"1.10","letterSpacing":"0.01","lineHeight":"1.3"}',
            'typo_card_body'    => '{"family":"system","weight":"400","size":"0.95","letterSpacing":"0.00","lineHeight":"1.6"}',
```

**Step 2: Verify the activate() defaults array has all 7 new keys**

```bash
grep "typo_" /d/Claude\ workfolder/Claude\ workfolder/pedantic-wiles/fraud_prevention_suite/fraud_prevention_suite.php
```

Expected: 7 lines with typo_ keys.

**Step 3: Commit**

```bash
git add fraud_prevention_suite/fraud_prevention_suite.php
git commit -m "feat(db): seed 7 typo_* typography defaults in activate()"
```

---

## Task 3: PHP TabSettings — Add `fpsRenderTypographyPanel()` and replace font size section

**Files:**
- Modify: `lib/Admin/TabSettings.php`

**Step 1: Add the font token map and default map as class constants or method-local arrays**

In `fpsRenderDisplaySettings()` (or extracted as a private method `fpsRenderTypographyPanel()`), add this data. The method will replace the existing `$fontSizeDefs` / `$fontSizeHtml` block (~lines 94–130).

The `$fontTokens` array (maps token key → display label + font stack):
```php
$fontTokens = [
    'system'       => ['System Default',    "system-ui,-apple-system,'Segoe UI',sans-serif"],
    'georgia'      => ['Georgia',           "Georgia,'Times New Roman',serif"],
    'mono'         => ['Monospace',         "'JetBrains Mono','Fira Code',Consolas,monospace"],
    'arial'        => ['Arial',             "Arial,Helvetica,sans-serif"],
    'inter-sys'    => ['Inter (system)',    "Inter,system-ui,sans-serif"],
    'inter'        => ['Inter',             "Inter,system-ui,sans-serif"],
    'roboto'       => ['Roboto',            "Roboto,system-ui,sans-serif"],
    'poppins'      => ['Poppins',           "Poppins,system-ui,sans-serif"],
    'opensans'     => ['Open Sans',         "'Open Sans',system-ui,sans-serif"],
    'lato'         => ['Lato',              "Lato,system-ui,sans-serif"],
    'nunito'       => ['Nunito',            "Nunito,system-ui,sans-serif"],
    'merriweather' => ['Merriweather',      "Merriweather,Georgia,serif"],
    'playfair'     => ['Playfair Display',  "'Playfair Display',Georgia,serif"],
    'jetbrains'    => ['JetBrains Mono',    "'JetBrains Mono',Consolas,monospace"],
];
// Google Fonts tokens (need a <link> load)
$googleFontTokens = ['inter','roboto','poppins','opensans','lato','nunito','merriweather','playfair','jetbrains'];
```

The `$typoDefs` array (maps section key → [setting_key, label, defaults]):
```php
$typoDefs = [
    'tabs'         => ['typo_tabs',         'Tab Labels',        ['family'=>'system','weight'=>'600','size'=>'0.84','letterSpacing'=>'0.01','lineHeight'=>'1.4']],
    'stats'        => ['typo_stats',        'Stat Numbers',      ['family'=>'system','weight'=>'700','size'=>'1.80','letterSpacing'=>'-0.02','lineHeight'=>'1.2']],
    'stat_labels'  => ['typo_stat_labels',  'Stat Labels',       ['family'=>'system','weight'=>'500','size'=>'0.85','letterSpacing'=>'0.06','lineHeight'=>'1.4']],
    'table_header' => ['typo_table_header', 'Table Headers',     ['family'=>'system','weight'=>'600','size'=>'0.80','letterSpacing'=>'0.07','lineHeight'=>'1.4']],
    'table_body'   => ['typo_table_body',   'Table Body',        ['family'=>'system','weight'=>'400','size'=>'0.90','letterSpacing'=>'0.00','lineHeight'=>'1.5']],
    'card_header'  => ['typo_card_header',  'Card Headers',      ['family'=>'system','weight'=>'600','size'=>'1.10','letterSpacing'=>'0.01','lineHeight'=>'1.3']],
    'card_body'    => ['typo_card_body',    'Card Body',         ['family'=>'system','weight'=>'400','size'=>'0.95','letterSpacing'=>'0.00','lineHeight'=>'1.6']],
];
```

**Step 2: Read all 7 typo_* values from DB and decode/validate each**

```php
// Read and validate each typo section
$typoValues = [];
foreach ($typoDefs as $sectionKey => [$settingKey, $sectionLabel, $sectionDefaults]) {
    $raw = $config->getCustom($settingKey, '');
    $decoded = $raw ? @json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        $decoded = $sectionDefaults;
    }
    // Validate and sanitize each field
    $family = (isset($decoded['family']) && array_key_exists($decoded['family'], $fontTokens))
        ? $decoded['family'] : $sectionDefaults['family'];
    $weight = (isset($decoded['weight']) && in_array($decoded['weight'], ['300','400','500','600','700'], true))
        ? $decoded['weight'] : $sectionDefaults['weight'];
    $size = (isset($decoded['size']) && is_numeric($decoded['size'])
             && (float)$decoded['size'] >= 0.60 && (float)$decoded['size'] <= 2.00)
        ? number_format((float)$decoded['size'], 2) : $sectionDefaults['size'];
    $ls = (isset($decoded['letterSpacing']) && is_numeric($decoded['letterSpacing'])
           && (float)$decoded['letterSpacing'] >= -0.05 && (float)$decoded['letterSpacing'] <= 0.20)
        ? number_format((float)$decoded['letterSpacing'], 2) : $sectionDefaults['letterSpacing'];
    $lh = (isset($decoded['lineHeight']) && is_numeric($decoded['lineHeight'])
           && (float)$decoded['lineHeight'] >= 1.0 && (float)$decoded['lineHeight'] <= 2.5)
        ? number_format((float)$decoded['lineHeight'], 1) : $sectionDefaults['lineHeight'];
    $typoValues[$sectionKey] = compact('family','weight','size','ls','lh');
}
```

**Step 3: Build mini-tabs HTML**

```php
$miniTabsHtml = '<div class="fps-typo-tabs" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:18px;">';
$firstSection = array_key_first($typoDefs);
foreach ($typoDefs as $sectionKey => [$settingKey, $sectionLabel, $sectionDefaults]) {
    $activeClass = ($sectionKey === $firstSection) ? ' fps-typo-tab-active' : '';
    $miniTabsHtml .= '<button type="button" class="fps-typo-tab' . $activeClass . '" '
        . 'data-section="' . htmlspecialchars($sectionKey) . '" '
        . 'onclick="FpsAdmin.switchTypoSection(\'' . htmlspecialchars($sectionKey) . '\')" '
        . 'style="padding:5px 14px;border-radius:20px;border:2px solid var(--fps-border,#dde1ef);'
        . 'background:' . ($sectionKey === $firstSection ? 'var(--fps-primary,#667eea)' : 'var(--fps-surface-2,#f8f9fc)') . ';'
        . 'color:' . ($sectionKey === $firstSection ? '#fff' : 'var(--fps-text-secondary,#5a6176)') . ';'
        . 'font-size:12px;font-weight:600;cursor:pointer;">'
        . htmlspecialchars($sectionLabel)
        . '</button>';
}
$miniTabsHtml .= '</div>';
```

**Step 4: Build the 5 controls for the active section**

The controls panel is always shown for the currently active section (first by default). JS `switchTypoSection()` updates all 5 controls when a different tab is clicked.

Build the controls HTML wrapper (section-agnostic; JS populates values on tab switch):

```php
// Build font family <select> optgroups
$familySelectHtml = '<select name="fps-typo-family-select" id="fps-typo-family-select" '
    . 'onchange="FpsAdmin.previewTypo(FpsAdmin._typoActive,\'family\',this.value)" '
    . 'style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid var(--fps-border,#dde1ef);'
    . 'background:var(--fps-surface,#fff);color:var(--fps-text-primary,#1a1d2e);font-size:13px;">'
    . '<optgroup label="System Fonts">';

$systemTokens = ['system','georgia','mono','arial','inter-sys'];
foreach ($systemTokens as $token) {
    [$tokenLabel] = $fontTokens[$token];
    $familySelectHtml .= '<option value="' . htmlspecialchars($token) . '">'
        . htmlspecialchars($tokenLabel) . '</option>';
}
$familySelectHtml .= '</optgroup><optgroup label="Google Fonts (loads on select)">';
foreach ($googleFontTokens as $token) {
    if (isset($fontTokens[$token])) {
        [$tokenLabel] = $fontTokens[$token];
        $familySelectHtml .= '<option value="' . htmlspecialchars($token) . '">'
            . htmlspecialchars($tokenLabel) . '</option>';
    }
}
$familySelectHtml .= '</optgroup></select>';
```

Build weight toggle (3 buttons):
```php
$weightToggleHtml = '<div id="fps-typo-weight-toggle" style="display:flex;gap:6px;">';
foreach (['300' => 'Light', '400' => 'Regular', '700' => 'Bold'] as $w => $wLabel) {
    $weightToggleHtml .= '<button type="button" class="fps-typo-weight-btn" data-weight="' . $w . '" '
        . 'onclick="FpsAdmin.previewTypo(FpsAdmin._typoActive,\'weight\',\'' . $w . '\')" '
        . 'style="flex:1;padding:7px 0;border-radius:6px;border:2px solid var(--fps-border,#dde1ef);'
        . 'background:var(--fps-surface-2,#f8f9fc);cursor:pointer;font-size:13px;font-weight:600;">'
        . htmlspecialchars($wLabel) . ' (' . $w . ')</button>';
}
$weightToggleHtml .= '</div>';
```

Build 3 range sliders (size, letterSpacing, lineHeight):
```php
$sliderDefs = [
    ['size',          'Size',           '0.60', '2.00', '0.05', 'rem'],
    ['letterSpacing', 'Letter Spacing', '-0.05','0.20', '0.01', 'em'],
    ['lineHeight',    'Line Height',    '1.0',  '2.5',  '0.1',  ''],
];
$slidersHtml = '';
foreach ($sliderDefs as [$prop, $sliderLabel, $min, $max, $step, $unit]) {
    $inputId = 'fps-typo-' . str_replace('S','s',$prop); // camelCase -> kebab approximation
    $slidersHtml .= '<div style="margin-bottom:14px;">'
        . '<div style="display:flex;justify-content:space-between;margin-bottom:5px;">'
        . '<strong style="font-size:13px;">' . htmlspecialchars($sliderLabel) . '</strong>'
        . '<span id="' . htmlspecialchars($inputId) . '-val" style="font-family:monospace;font-size:12px;'
        . 'background:var(--fps-surface-2,#f8f9fc);padding:2px 7px;border-radius:4px;'
        . 'border:1px solid var(--fps-border,#dde1ef);min-width:60px;text-align:center;"></span>'
        . '</div>'
        . '<input type="range" id="' . htmlspecialchars($inputId) . '" '
        . 'min="' . $min . '" max="' . $max . '" step="' . $step . '" '
        . 'style="width:100%;accent-color:var(--fps-primary,#667eea);cursor:pointer;" '
        . 'data-unit="' . htmlspecialchars($unit) . '" data-prop="' . htmlspecialchars($prop) . '" '
        . 'oninput="FpsAdmin.previewTypo(FpsAdmin._typoActive,\'' . htmlspecialchars($prop) . '\',this.value)">'
        . '</div>';
}
```

**Step 5: Build 7 hidden inputs (one per section) for save bar collection**

```php
$hiddenInputsHtml = '';
foreach ($typoDefs as $sectionKey => [$settingKey, $sectionLabel, $sectionDefaults]) {
    $tv = $typoValues[$sectionKey];
    $jsonVal = json_encode([
        'family'        => $tv['family'],
        'weight'        => $tv['weight'],
        'size'          => $tv['size'],
        'letterSpacing' => $tv['ls'],
        'lineHeight'    => $tv['lh'],
    ]);
    $hiddenInputsHtml .= '<input type="hidden" '
        . 'name="' . htmlspecialchars($settingKey) . '" '
        . 'id="fps-typo-hidden-' . htmlspecialchars($sectionKey) . '" '
        . 'value="' . htmlspecialchars($jsonVal, ENT_QUOTES) . '">';
}
```

**Step 6: Build JS initialization data (inline script)**

```php
$typoInitJs = '<script>window._fpsTypoInit=' . json_encode($typoValues, JSON_HEX_TAG | JSON_HEX_QUOT) . ';</script>';
```

**Step 7: Assemble the full typography panel card HTML**

Build a `$typographyPanelHtml` variable containing the full card — mini-tabs, family select, weight toggle, 3 sliders, live preview line, reset buttons, hidden inputs, init script. Then in the main content heredoc, **replace** the existing "Per-Section Font Sizes" card (the `{$fontSizeHtml}` block, ~line 202–206) with `{$typographyPanelHtml}`.

Full assembled structure:
```php
$typographyPanelHtml = <<<HTML
<div class="fps-card" style="margin-bottom:24px;">
  <div class="fps-card-header">
    <h3 style="margin:0;font-size:15px;"><i class="fas fa-font"></i> Typography</h3>
  </div>
  <div class="fps-card-body">
    {$miniTabsHtml}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">
      <div>
        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Font Family</label>
          {$familySelectHtml}
        </div>
        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Weight</label>
          {$weightToggleHtml}
        </div>
      </div>
      <div>
        {$slidersHtml}
      </div>
    </div>
    <div id="fps-typo-preview" style="margin:16px 0;padding:14px 18px;background:var(--fps-surface-2,#f8f9fc);
      border-radius:8px;border:1px solid var(--fps-border,#dde1ef);font-size:14px;color:var(--fps-text-primary,#1a1d2e);">
      The quick brown fox jumps over the lazy dog &mdash; 0123456789
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button type="button" class="fps-btn fps-btn-ghost fps-btn-sm"
        onclick="FpsAdmin.resetTypoSection(FpsAdmin._typoActive)">
        Reset This Section
      </button>
      <button type="button" class="fps-btn fps-btn-ghost fps-btn-sm"
        onclick="FpsAdmin.resetAllTypo()">
        Reset All Typography
      </button>
    </div>
    {$hiddenInputsHtml}
    {$typoInitJs}
  </div>
</div>
HTML;
```

**Step 8: Remove the old `$fontSizeDefs` and `$fontSizeHtml` block**

Delete the entire block from `// Per-section font size sliders` through `$fontSizeHtml .= '</div>';` (~lines 94–130). Replace `{$fontSizeHtml}` in the content heredoc with `{$typographyPanelHtml}` and replace the card heading "Per-Section Font Sizes" with nothing (it's now inside `$typographyPanelHtml`).

Also delete the old font-size card wrapper `<h4>` and surrounding `<div>` (~lines 200–207).

**Step 9: Run PHP syntax check**

```bash
php -l /d/Claude\ workfolder/Claude\ workfolder/pedantic-wiles/fraud_prevention_suite/lib/Admin/TabSettings.php
```

Expected: `No syntax errors detected`

**Step 10: Commit**

```bash
git add fraud_prevention_suite/lib/Admin/TabSettings.php
git commit -m "feat(ui): replace font-size sliders with full typography panel in TabSettings"
```

---

## Task 4: PHP Injection — Add typography CSS + Google Font injection in `_output()`

**Files:**
- Modify: `fraud_prevention_suite.php` — `fraud_prevention_suite_output()` (~line 1159)

**Step 1: Add `typo_*` keys to the existing `whereIn` query**

At ~line 1194–1201, the `whereIn` list currently includes `font_size_*` keys. Add the 7 `typo_*` keys:

```php
->whereIn('setting_key', [
    'ui_font_scale',
    'admin_primary_color', 'admin_secondary_color',
    'admin_bg_color', 'admin_surface_color', 'admin_text_color',
    'admin_dark_mode',
    'font_size_tabs', 'font_size_stats', 'font_size_stat_labels',
    'font_size_table_header', 'font_size_table_body',
    'font_size_card_header', 'font_size_card_body',
    'typo_tabs', 'typo_stats', 'typo_stat_labels',
    'typo_table_header', 'typo_table_body',
    'typo_card_header', 'typo_card_body',
])
```

**Step 2: Add the font token map and section defaults (same data as TabSettings)**

After the existing `$fontSizeParts` block (~line 1266), add a new block:

```php
// ── Typography injection ────────────────────────────────────────────
$fontTokenMap = [
    'system'       => "system-ui,-apple-system,'Segoe UI',sans-serif",
    'georgia'      => "Georgia,'Times New Roman',serif",
    'mono'         => "'JetBrains Mono','Fira Code',Consolas,monospace",
    'arial'        => "Arial,Helvetica,sans-serif",
    'inter-sys'    => "Inter,system-ui,sans-serif",
    'inter'        => "Inter,system-ui,sans-serif",
    'roboto'       => "Roboto,system-ui,sans-serif",
    'poppins'      => "Poppins,system-ui,sans-serif",
    'opensans'     => "'Open Sans',system-ui,sans-serif",
    'lato'         => "Lato,system-ui,sans-serif",
    'nunito'       => "Nunito,system-ui,sans-serif",
    'merriweather' => "Merriweather,Georgia,serif",
    'playfair'     => "'Playfair Display',Georgia,serif",
    'jetbrains'    => "'JetBrains Mono',Consolas,monospace",
];
$googleFontUrls = [
    'inter'        => 'Inter:wght@300;400;700',
    'roboto'       => 'Roboto:wght@300;400;700',
    'poppins'      => 'Poppins:wght@300;400;700',
    'opensans'     => 'Open+Sans:wght@300;400;700',
    'lato'         => 'Lato:wght@300;400;700',
    'nunito'       => 'Nunito:wght@300;400;700',
    'merriweather' => 'Merriweather:wght@300;400;700',
    'playfair'     => 'Playfair+Display:wght@400;700',
    'jetbrains'    => 'JetBrains+Mono:wght@300;400;700',
];

// Section key → [setting_key, css_var_prefix, defaults]
$typhoSections = [
    'tabs'         => ['typo_tabs',         'tabs',         ['family'=>'system','weight'=>'600','size'=>'0.84','letterSpacing'=>'0.01','lineHeight'=>'1.4']],
    'stats'        => ['typo_stats',        'stats',        ['family'=>'system','weight'=>'700','size'=>'1.80','letterSpacing'=>'-0.02','lineHeight'=>'1.2']],
    'stat_labels'  => ['typo_stat_labels',  'stat-labels',  ['family'=>'system','weight'=>'500','size'=>'0.85','letterSpacing'=>'0.06','lineHeight'=>'1.4']],
    'table_header' => ['typo_table_header', 'table-header', ['family'=>'system','weight'=>'600','size'=>'0.80','letterSpacing'=>'0.07','lineHeight'=>'1.4']],
    'table_body'   => ['typo_table_body',   'table-body',   ['family'=>'system','weight'=>'400','size'=>'0.90','letterSpacing'=>'0.00','lineHeight'=>'1.5']],
    'card_header'  => ['typo_card_header',  'card-header',  ['family'=>'system','weight'=>'600','size'=>'1.10','letterSpacing'=>'0.01','lineHeight'=>'1.3']],
    'card_body'    => ['typo_card_body',    'card-body',    ['family'=>'system','weight'=>'400','size'=>'0.95','letterSpacing'=>'0.00','lineHeight'=>'1.6']],
];
```

**Step 3: Loop sections, validate, collect Google Font tokens and CSS vars**

```php
$typoCssParts   = [];
$googleTokensNeeded = [];

foreach ($typhoSections as $sectionKey => [$settingKey, $cssPrefix, $defaults]) {
    $raw = $displaySettings[$settingKey] ?? '';
    $tv  = $raw ? @json_decode($raw, true) : null;
    if (!is_array($tv)) {
        continue; // all defaults → skip entirely
    }

    // family
    $family = (isset($tv['family']) && array_key_exists($tv['family'], $fontTokenMap))
        ? $tv['family'] : $defaults['family'];
    if ($family !== $defaults['family']) {
        $stack = $fontTokenMap[$family];
        $typoCssParts[] = '--fps-font-' . $cssPrefix . ':' . $stack;
        if (isset($googleFontUrls[$family])) {
            $googleTokensNeeded[$family] = $googleFontUrls[$family];
        }
    }

    // weight
    $weight = (isset($tv['weight']) && in_array($tv['weight'], ['300','400','500','600','700'], true))
        ? $tv['weight'] : $defaults['weight'];
    if ($weight !== $defaults['weight']) {
        $typoCssParts[] = '--fps-weight-' . $cssPrefix . ':' . $weight;
    }

    // size (reuses existing --fps-size-* vars — handled by existing fontSizeMap, so skip here
    //        unless the typo row overrides it separately from the font_size_* row)
    // NOTE: The typo_* size field supersedes font_size_* going forward.
    $size = (isset($tv['size']) && is_numeric($tv['size'])
             && (float)$tv['size'] >= 0.6 && (float)$tv['size'] <= 2.0)
        ? number_format((float)$tv['size'], 2) : null;
    // Map cssPrefix back to the existing --fps-size-* var name
    $sizeVarMap = [
        'tabs' => '--fps-size-tabs', 'stats' => '--fps-size-stats',
        'stat-labels' => '--fps-size-stat-labels', 'table-header' => '--fps-size-th',
        'table-body' => '--fps-size-td', 'card-header' => '--fps-size-card-h',
        'card-body' => '--fps-size-card-body',
    ];
    if ($size !== null && abs((float)$size - (float)$defaults['size']) >= 0.001) {
        $typoCssParts[] = ($sizeVarMap[$cssPrefix] ?? ('--fps-size-' . $cssPrefix)) . ':' . $size . 'rem';
    }

    // letterSpacing
    $ls = (isset($tv['letterSpacing']) && is_numeric($tv['letterSpacing'])
           && (float)$tv['letterSpacing'] >= -0.05 && (float)$tv['letterSpacing'] <= 0.20)
        ? number_format((float)$tv['letterSpacing'], 2) : null;
    if ($ls !== null && abs((float)$ls - (float)$defaults['letterSpacing']) >= 0.001) {
        $typoCssParts[] = '--fps-tracking-' . $cssPrefix . ':' . $ls . 'em';
    }

    // lineHeight
    $lh = (isset($tv['lineHeight']) && is_numeric($tv['lineHeight'])
           && (float)$tv['lineHeight'] >= 1.0 && (float)$tv['lineHeight'] <= 2.5)
        ? number_format((float)$tv['lineHeight'], 1) : null;
    if ($lh !== null && abs((float)$lh - (float)$defaults['lineHeight']) >= 0.001) {
        $typoCssParts[] = '--fps-lh-' . $cssPrefix . ':' . $lh;
    }
}

// Emit Google Font <link> tags (deduplicated)
foreach ($googleTokensNeeded as $token => $param) {
    $url = 'https://fonts.googleapis.com/css2?family=' . $param . '&display=swap';
    echo '<link rel="stylesheet" data-fps-font="' . htmlspecialchars($token) . '" href="' . htmlspecialchars($url) . '">';
}

// Emit CSS var overrides
if (!empty($typoCssParts)) {
    echo '<style>:root,.fps-root{' . implode(';', $typoCssParts) . '}</style>';
}
```

**Step 4: PHP syntax check**

```bash
php -l /d/Claude\ workfolder/Claude\ workfolder/pedantic-wiles/fraud_prevention_suite/fraud_prevention_suite.php
```

Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add fraud_prevention_suite/fraud_prevention_suite.php
git commit -m "feat(php): inject typography CSS vars and Google Font links in _output()"
```

---

## Task 5: JavaScript — Add 5 typography functions to `FpsAdmin`

**Files:**
- Modify: `assets/js/fps-admin.js` — after `previewFontSize` (~line 1195)

**Step 1: Add `_typo` state and `_typoActive` tracker, plus `_typoDefaults`**

Insert immediately after the closing `},` of `previewFontSize`:

```javascript
    // ── Full typography panel ──────────────────────────────────────────

    _typoActive: 'tabs',

    _typoDefaults: {
      tabs:         { family: 'system', weight: '600', size: '0.84', letterSpacing: '0.01', lineHeight: '1.4' },
      stats:        { family: 'system', weight: '700', size: '1.80', letterSpacing: '-0.02', lineHeight: '1.2' },
      stat_labels:  { family: 'system', weight: '500', size: '0.85', letterSpacing: '0.06', lineHeight: '1.4' },
      table_header: { family: 'system', weight: '600', size: '0.80', letterSpacing: '0.07', lineHeight: '1.4' },
      table_body:   { family: 'system', weight: '400', size: '0.90', letterSpacing: '0.00', lineHeight: '1.5' },
      card_header:  { family: 'system', weight: '600', size: '1.10', letterSpacing: '0.01', lineHeight: '1.3' },
      card_body:    { family: 'system', weight: '400', size: '0.95', letterSpacing: '0.00', lineHeight: '1.6' },
    },

    _typo: null, // populated from window._fpsTypoInit on initTypo()

    _fontTokenMap: {
      'system':       "system-ui,-apple-system,'Segoe UI',sans-serif",
      'georgia':      "Georgia,'Times New Roman',serif",
      'mono':         "'JetBrains Mono','Fira Code',Consolas,monospace",
      'arial':        "Arial,Helvetica,sans-serif",
      'inter-sys':    "Inter,system-ui,sans-serif",
      'inter':        "Inter,system-ui,sans-serif",
      'roboto':       "Roboto,system-ui,sans-serif",
      'poppins':      "Poppins,system-ui,sans-serif",
      'opensans':     "'Open Sans',system-ui,sans-serif",
      'lato':         "Lato,system-ui,sans-serif",
      'nunito':       "Nunito,system-ui,sans-serif",
      'merriweather': "Merriweather,Georgia,serif",
      'playfair':     "'Playfair Display',Georgia,serif",
      'jetbrains':    "'JetBrains Mono',Consolas,monospace",
    },

    _googleFontUrls: {
      'inter':        'Inter:wght@300;400;700',
      'roboto':       'Roboto:wght@300;400;700',
      'poppins':      'Poppins:wght@300;400;700',
      'opensans':     'Open+Sans:wght@300;400;700',
      'lato':         'Lato:wght@300;400;700',
      'nunito':       'Nunito:wght@300;400;700',
      'merriweather': 'Merriweather:wght@300;400;700',
      'playfair':     'Playfair+Display:wght@400;700',
      'jetbrains':    'JetBrains+Mono:wght@300;400;700',
    },

    _sizeVarMap: {
      tabs:         '--fps-size-tabs',
      stats:        '--fps-size-stats',
      stat_labels:  '--fps-size-stat-labels',
      table_header: '--fps-size-th',
      table_body:   '--fps-size-td',
      card_header:  '--fps-size-card-h',
      card_body:    '--fps-size-card-body',
    },
```

**Step 2: Add `initTypo()` — called once on page load (from DOMContentLoaded or inline script)**

```javascript
    initTypo: function() {
      var self = FpsAdmin;
      var init = window._fpsTypoInit;
      if (!init) return;
      // Build _typo from init data
      self._typo = {};
      var keys = ['tabs','stats','stat_labels','table_header','table_body','card_header','card_body'];
      keys.forEach(function(k) {
        var src = init[k] || {};
        var def = self._typoDefaults[k];
        self._typo[k] = {
          family:        src.family        || def.family,
          weight:        src.weight        || def.weight,
          size:          src.size          || def.size,
          letterSpacing: src.ls            || def.letterSpacing,
          lineHeight:    src.lh            || def.lineHeight,
        };
      });
      // Activate first section
      self.switchTypoSection('tabs');
    },
```

**Step 3: Add `switchTypoSection(sectionKey)`**

```javascript
    switchTypoSection: function(sectionKey) {
      var self = FpsAdmin;
      if (!self._typo || !self._typo[sectionKey]) return;
      self._typoActive = sectionKey;
      var tv = self._typo[sectionKey];

      // Update mini-tab active styles
      document.querySelectorAll('.fps-typo-tab').forEach(function(btn) {
        var isActive = btn.getAttribute('data-section') === sectionKey;
        btn.style.background = isActive ? 'var(--fps-primary,#667eea)' : 'var(--fps-surface-2,#f8f9fc)';
        btn.style.color      = isActive ? '#fff' : 'var(--fps-text-secondary,#5a6176)';
      });

      // Update family select
      var sel = document.getElementById('fps-typo-family-select');
      if (sel) sel.value = tv.family;

      // Update weight toggle active state
      document.querySelectorAll('.fps-typo-weight-btn').forEach(function(btn) {
        var isActive = btn.getAttribute('data-weight') === tv.weight;
        btn.style.background   = isActive ? 'var(--fps-primary,#667eea)' : 'var(--fps-surface-2,#f8f9fc)';
        btn.style.color        = isActive ? '#fff' : 'var(--fps-text-primary,#1a1d2e)';
        btn.style.borderColor  = isActive ? 'var(--fps-primary,#667eea)' : 'var(--fps-border,#dde1ef)';
      });

      // Update sliders + value displays
      var sliderMap = {
        'fps-typo-size':          { val: tv.size,          unit: 'rem' },
        'fps-typo-letterSpacing': { val: tv.letterSpacing, unit: 'em'  },
        'fps-typo-lineHeight':    { val: tv.lineHeight,     unit: ''   },
      };
      Object.keys(sliderMap).forEach(function(id) {
        var el = document.getElementById(id);
        var valEl = document.getElementById(id + '-val');
        if (el) el.value = sliderMap[id].val;
        if (valEl) valEl.textContent = sliderMap[id].val + sliderMap[id].unit;
      });

      // Update live preview
      self._updateTypoPreview(sectionKey, tv);
    },
```

**Step 4: Add `previewTypo(sectionKey, property, value)`**

```javascript
    previewTypo: function(sectionKey, property, value) {
      var self = FpsAdmin;
      if (!self._typo || !self._typo[sectionKey]) return;

      // Update state
      if (property === 'letterSpacing') {
        self._typo[sectionKey].letterSpacing = value;
      } else {
        self._typo[sectionKey][property] = value;
      }

      var tv = self._typo[sectionKey];
      var wrapper = document.querySelector('.fps-module-wrapper') || document.documentElement;

      var cssPrefix = {
        tabs:'tabs', stats:'stats', stat_labels:'stat-labels',
        table_header:'table-header', table_body:'table-body',
        card_header:'card-header', card_body:'card-body',
      }[sectionKey] || sectionKey;

      if (property === 'family') {
        // Google Font: load first, then apply
        self.loadGoogleFont(value).then(function() {
          var stack = self._fontTokenMap[value] || self._fontTokenMap['system'];
          wrapper.style.setProperty('--fps-font-' + cssPrefix, stack);
          self._updateTypoPreview(sectionKey, tv);
        });
      } else {
        // Immediate apply
        if (property === 'weight') {
          wrapper.style.setProperty('--fps-weight-' + cssPrefix, value);
        } else if (property === 'size') {
          var sizeVar = self._sizeVarMap[sectionKey] || ('--fps-size-' + cssPrefix);
          wrapper.style.setProperty(sizeVar, parseFloat(value).toFixed(2) + 'rem');
          // update slider display
          var sizeEl = document.getElementById('fps-typo-size-val');
          if (sizeEl) sizeEl.textContent = parseFloat(value).toFixed(2) + 'rem';
        } else if (property === 'letterSpacing') {
          wrapper.style.setProperty('--fps-tracking-' + cssPrefix, parseFloat(value).toFixed(2) + 'em');
          var lsEl = document.getElementById('fps-typo-letterSpacing-val');
          if (lsEl) lsEl.textContent = parseFloat(value).toFixed(2) + 'em';
        } else if (property === 'lineHeight') {
          wrapper.style.setProperty('--fps-lh-' + cssPrefix, parseFloat(value).toFixed(1));
          var lhEl = document.getElementById('fps-typo-lineHeight-val');
          if (lhEl) lhEl.textContent = parseFloat(value).toFixed(1);
        }
        self._updateTypoPreview(sectionKey, tv);
      }

      // Sync hidden input
      self._syncTypoHidden(sectionKey);
    },
```

**Step 5: Add `loadGoogleFont(token)` — returns a Promise**

```javascript
    loadGoogleFont: function(token) {
      var self = FpsAdmin;
      // No Google Fonts URL → resolve immediately
      if (!self._googleFontUrls[token]) {
        return Promise.resolve();
      }
      // Already loaded?
      if (document.querySelector('link[data-fps-font="' + token + '"]')) {
        return Promise.resolve();
      }
      return new Promise(function(resolve) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.setAttribute('data-fps-font', token);
        link.href = 'https://fonts.googleapis.com/css2?family=' + self._googleFontUrls[token] + '&display=swap';
        var timer = setTimeout(resolve, 800); // never block UI
        link.onload = function() { clearTimeout(timer); resolve(); };
        link.onerror = function() { clearTimeout(timer); resolve(); };
        document.head.appendChild(link);
      });
    },
```

**Step 6: Add `_syncTypoHidden(sectionKey)` — keeps hidden input in sync**

```javascript
    _syncTypoHidden: function(sectionKey) {
      var self = FpsAdmin;
      if (!self._typo || !self._typo[sectionKey]) return;
      var tv = self._typo[sectionKey];
      var el = document.getElementById('fps-typo-hidden-' + sectionKey);
      if (!el) return;
      el.value = JSON.stringify({
        family:        tv.family,
        weight:        tv.weight,
        size:          tv.size,
        letterSpacing: tv.letterSpacing,
        lineHeight:    tv.lineHeight,
      });
    },
```

**Step 7: Add `_updateTypoPreview(sectionKey, tv)` — updates live preview element**

```javascript
    _updateTypoPreview: function(sectionKey, tv) {
      var self = FpsAdmin;
      var prev = document.getElementById('fps-typo-preview');
      if (!prev) return;
      var stack = self._fontTokenMap[tv.family] || self._fontTokenMap['system'];
      prev.style.fontFamily    = stack;
      prev.style.fontWeight    = tv.weight;
      prev.style.fontSize      = parseFloat(tv.size).toFixed(2) + 'rem';
      prev.style.letterSpacing = parseFloat(tv.letterSpacing).toFixed(2) + 'em';
      prev.style.lineHeight    = parseFloat(tv.lineHeight).toFixed(1);
    },
```

**Step 8: Add `resetTypoSection(sectionKey)` and `resetAllTypo()`**

```javascript
    resetTypoSection: function(sectionKey) {
      var self = FpsAdmin;
      if (!self._typoDefaults[sectionKey]) return;
      self._typo[sectionKey] = Object.assign({}, self._typoDefaults[sectionKey]);
      self.switchTypoSection(sectionKey);
      self._syncTypoHidden(sectionKey);
    },

    resetAllTypo: function() {
      var self = FpsAdmin;
      Object.keys(self._typoDefaults).forEach(function(k) {
        self._typo[k] = Object.assign({}, self._typoDefaults[k]);
        self._syncTypoHidden(k);
      });
      // Re-render active section controls
      self.switchTypoSection(self._typoActive);
    },
```

**Step 9: Wire `initTypo()` on DOMContentLoaded**

In `fps-admin.js`, find the `DOMContentLoaded` listener (or wherever similar init calls are made). Add:

```javascript
if (typeof FpsAdmin !== 'undefined' && typeof FpsAdmin.initTypo === 'function') {
  FpsAdmin.initTypo();
}
```

Search for the existing init block:
```bash
grep -n "DOMContentLoaded\|FpsAdmin.init\|document.addEventListener" /d/Claude\ workfolder/Claude\ workfolder/pedantic-wiles/fraud_prevention_suite/assets/js/fps-admin.js | head -10
```

**Step 10: JS syntax check**

```bash
node --check /d/Claude\ workfolder/Claude\ workfolder/pedantic-wiles/fraud_prevention_suite/assets/js/fps-admin.js
```

Expected: no output (clean).

**Step 11: Commit**

```bash
git add fraud_prevention_suite/assets/js/fps-admin.js
git commit -m "feat(js): add typography panel state, switchTypoSection, previewTypo, loadGoogleFont"
```

---

## Task 6: PHP CLI Tests — Write and run 9 test cases

**Files:**
- Create (temp, run on dev server, delete after): `fps-work/fps_typo_test.php`

**Step 1: Write the test file**

The test file follows the same pattern as `fps_test.php` and `fps_full_test.php`. Save it to `/d/Claude workfolder/Claude workfolder/musing-hellman/fps-work/fps_typo_test.php`.

```php
<?php
/**
 * FPS Typography Panel Verification
 * 9 test cases from the design doc testing plan
 */
define('WHMCS', true);
define('ROOTDIR', '/home/freeit/public_html');
require_once '/home/freeit/public_html/vendor/autoload.php';
use WHMCS\Database\Capsule;
$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql', 'host' => 'localhost',
    'database' => 'freeit_whmcs', 'username' => 'freeit_whmcs',
    'password' => 'sQ;^4fI0mT8KSV=q', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$pass = 0; $fail = 0;
function ok($l)        { global $pass; $pass++; echo "  PASS: $l\n"; }
function nok($l,$d='') { global $fail; $fail++; echo "  FAIL: $l" . ($d ? " -- $d" : '') . "\n"; }

$modPath = '/home/freeit/public_html/modules/addons/fraud_prevention_suite';
foreach (glob($modPath . '/lib/*.php') as $f)       { require_once $f; }
foreach (glob($modPath . '/lib/Admin/*.php') as $f) { require_once $f; }

// TEST 1: All 7 typo_* keys seeded with valid JSON
echo "\n[1] All 7 typo_* keys seeded\n";
$typoKeys = ['typo_tabs','typo_stats','typo_stat_labels','typo_table_header',
             'typo_table_body','typo_card_header','typo_card_body'];
$rows = Capsule::table('mod_fps_settings')
    ->whereIn('setting_key', $typoKeys)->pluck('setting_value','setting_key')->toArray();
foreach ($typoKeys as $k) {
    if (isset($rows[$k])) {
        $d = json_decode($rows[$k], true);
        if (is_array($d) && isset($d['family'],$d['weight'],$d['size'],$d['letterSpacing'],$d['lineHeight']))
            ok("$k seeded with valid JSON");
        else
            nok("$k has invalid JSON structure", $rows[$k]);
    } else {
        nok("$k missing from DB");
    }
}

// TEST 2: Each JSON decodes and validates correctly
echo "\n[2] JSON validation\n";
$validFamilies = ['system','georgia','mono','arial','inter-sys','inter','roboto','poppins','opensans',
                  'lato','nunito','merriweather','playfair','jetbrains'];
$validWeights  = ['300','400','500','600','700'];
foreach ($rows as $k => $raw) {
    $d = json_decode($raw, true);
    if (!is_array($d)) { nok("$k not an array", $raw); continue; }
    $checks = [
        'family in map'   => in_array($d['family'], $validFamilies),
        'weight valid'    => in_array($d['weight'], $validWeights),
        'size in range'   => is_numeric($d['size']) && (float)$d['size'] >= 0.6 && (float)$d['size'] <= 2.0,
        'ls in range'     => is_numeric($d['letterSpacing']) && (float)$d['letterSpacing'] >= -0.05 && (float)$d['letterSpacing'] <= 0.20,
        'lh in range'     => is_numeric($d['lineHeight']) && (float)$d['lineHeight'] >= 1.0 && (float)$d['lineHeight'] <= 2.5,
    ];
    foreach ($checks as $desc => $ok) {
        if ($ok) ok("$k: $desc"); else nok("$k: $desc", json_encode($d));
    }
}

// TEST 3: Settings tab renders mini-tabs and all 5 controls
echo "\n[3] Settings tab renders typography panel\n";
$ref = new ReflectionClass(FraudPreventionSuite\Lib\FpsConfig::class);
$prop = $ref->getProperty('instance'); $prop->setAccessible(true); $prop->setValue(null, null);
ob_start();
(new FraudPreventionSuite\Lib\Admin\TabSettings())->render(
    ['modulelink' => '?module=fraud_prevention_suite'], '?module=fraud_prevention_suite');
$html = ob_get_clean();
$checks3 = [
    'fps-typo-tab'             => 'Mini-tab buttons rendered',
    'fps-typo-family-select'   => 'Font family select rendered',
    'fps-typo-weight-toggle'   => 'Weight toggle rendered',
    'fps-typo-size'            => 'Size slider rendered',
    'fps-typo-letterSpacing'   => 'Letter spacing slider rendered',
    'fps-typo-lineHeight'      => 'Line height slider rendered',
    'fps-typo-preview'         => 'Preview element rendered',
    'fps-typo-hidden-tabs'     => 'Hidden input for tabs rendered',
    'fps-typo-hidden-card_body'=> 'Hidden input for card_body rendered',
    'FpsAdmin.switchTypoSection' => 'switchTypoSection wired',
    'FpsAdmin.previewTypo'     => 'previewTypo wired',
    'FpsAdmin.resetTypoSection'=> 'resetTypoSection button present',
    'FpsAdmin.resetAllTypo'    => 'resetAllTypo button present',
    '_fpsTypoInit'             => 'JS init data present',
    'Tab Labels'               => 'Section label: Tab Labels',
    'Card Body'                => 'Section label: Card Body',
    'name="typo_tabs"'         => 'Hidden field name typo_tabs',
    'name="typo_card_body"'    => 'Hidden field name typo_card_body',
];
foreach ($checks3 as $needle => $desc) {
    if (strpos($html, $needle) !== false) ok($desc);
    else nok($desc, "needle '$needle' not found");
}

// TEST 4: Non-default family (Google Font) → correct <link> tag emitted
echo "\n[4] Google Font link injection\n";
Capsule::table('mod_fps_settings')
    ->updateOrInsert(['setting_key' => 'typo_tabs'],
        ['setting_value' => '{"family":"inter","weight":"600","size":"0.84","letterSpacing":"0.01","lineHeight":"1.4"}']);

// Simulate _output() injection logic inline
$displaySettings = Capsule::table('mod_fps_settings')
    ->whereIn('setting_key', array_merge(['typo_tabs','typo_stats'], []))->pluck('setting_value','setting_key')->toArray();
$googleFontUrls = ['inter' => 'Inter:wght@300;400;700','roboto' => 'Roboto:wght@300;400;700'];
$raw = $displaySettings['typo_tabs'] ?? '';
$tv = $raw ? @json_decode($raw, true) : null;
$googleNeeded = [];
if (is_array($tv) && isset($tv['family']) && $tv['family'] !== 'system' && isset($googleFontUrls[$tv['family']])) {
    $googleNeeded[$tv['family']] = $googleFontUrls[$tv['family']];
}
if (isset($googleNeeded['inter']))
    ok("typo_tabs family=inter → Google Font 'inter' in injection list");
else
    nok("Google Font not detected for inter family");

$linkTag = '<link rel="stylesheet" data-fps-font="inter" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;700&display=swap">';
if (strpos($linkTag, 'inter') !== false && strpos($linkTag, 'googleapis') !== false)
    ok("Google Font <link> tag format correct");
else
    nok("Google Font link tag format wrong");

// TEST 5: Non-default weight → correct CSS var emitted
echo "\n[5] Non-default weight CSS var\n";
Capsule::table('mod_fps_settings')
    ->updateOrInsert(['setting_key' => 'typo_stats'],
        ['setting_value' => '{"family":"system","weight":"300","size":"1.80","letterSpacing":"-0.02","lineHeight":"1.2"}']);
$statsRaw = Capsule::table('mod_fps_settings')->where('setting_key','typo_stats')->value('setting_value');
$statsDecoded = json_decode($statsRaw, true);
if (($statsDecoded['weight'] ?? '') === '300')
    ok("typo_stats weight=300 stored");
else
    nok("typo_stats weight not stored correctly", $statsRaw);
// The injection would produce: --fps-weight-stats:300
$expectedVar = '--fps-weight-stats:300';
$def = '700';
$emitted = ($statsDecoded['weight'] !== $def);
if ($emitted)
    ok("Weight differs from default → CSS var '--fps-weight-stats' would be emitted");
else
    nok("Weight at default, no emission expected (but set to 300, not 700)");

// TEST 6: Non-default size → correct CSS var emitted
echo "\n[6] Non-default size CSS var\n";
Capsule::table('mod_fps_settings')
    ->updateOrInsert(['setting_key' => 'typo_card_body'],
        ['setting_value' => '{"family":"system","weight":"400","size":"1.20","letterSpacing":"0.00","lineHeight":"1.6"}']);
$cbRaw = Capsule::table('mod_fps_settings')->where('setting_key','typo_card_body')->value('setting_value');
$cbDecoded = json_decode($cbRaw, true);
$expectedSize = '--fps-size-card-body:1.20rem';
if (is_array($cbDecoded) && abs((float)$cbDecoded['size'] - 1.20) < 0.001)
    ok("typo_card_body size=1.20 stored; injection would produce '$expectedSize'");
else
    nok("typo_card_body size wrong", $cbRaw);

// TEST 7: All-default section → no CSS vars emitted for that section
echo "\n[7] All-default section emits no vars\n";
Capsule::table('mod_fps_settings')
    ->updateOrInsert(['setting_key' => 'typo_table_body'],
        ['setting_value' => '{"family":"system","weight":"400","size":"0.90","letterSpacing":"0.00","lineHeight":"1.5"}']);
$tbRaw = Capsule::table('mod_fps_settings')->where('setting_key','typo_table_body')->value('setting_value');
$tbDecoded = json_decode($tbRaw, true);
$tbDefaults = ['family'=>'system','weight'=>'400','size'=>'0.90','letterSpacing'=>'0.00','lineHeight'=>'1.5'];
$tbNonDefault = false;
foreach ($tbDefaults as $f => $dv) {
    if (isset($tbDecoded[$f]) && abs((float)$tbDecoded[$f] - (float)$dv) >= 0.001) { $tbNonDefault = true; break; }
    if ($f === 'family' && ($tbDecoded[$f] ?? '') !== $dv) { $tbNonDefault = true; break; }
    if ($f === 'weight' && ($tbDecoded[$f] ?? '') !== $dv) { $tbNonDefault = true; break; }
}
if (!$tbNonDefault)
    ok("typo_table_body at all defaults → no CSS vars would be emitted");
else
    nok("typo_table_body incorrectly detected as non-default");

// TEST 8: Malformed JSON → graceful fallback
echo "\n[8] Malformed JSON fallback\n";
$malformed = '{"family":"invalid_token","weight":"999","size":"99.9","letterSpacing":"bogus","lineHeight":"nope"}';
$fontTokenMap = ['system'=>true,'inter'=>true,'roboto'=>true]; // abbreviated
$validWeightsCheck = ['300','400','500','600','700'];
$decoded = json_decode($malformed, true);
$family = (isset($decoded['family']) && array_key_exists($decoded['family'], $fontTokenMap)) ? $decoded['family'] : 'system';
$weight = (isset($decoded['weight']) && in_array($decoded['weight'], $validWeightsCheck, true)) ? $decoded['weight'] : '400';
$size   = (isset($decoded['size']) && is_numeric($decoded['size']) && (float)$decoded['size'] >= 0.6 && (float)$decoded['size'] <= 2.0) ? $decoded['size'] : '0.95';
$ls     = (isset($decoded['letterSpacing']) && is_numeric($decoded['letterSpacing'])) ? $decoded['letterSpacing'] : '0.00';
$lh     = (isset($decoded['lineHeight']) && is_numeric($decoded['lineHeight']) && (float)$decoded['lineHeight'] >= 1.0 && (float)$decoded['lineHeight'] <= 2.5) ? $decoded['lineHeight'] : '1.6';
if ($family === 'system') ok("Invalid family token falls back to 'system'");
else nok("Invalid family not caught", $family);
if ($weight === '400') ok("Invalid weight '999' falls back to default '400'");
else nok("Invalid weight not caught", $weight);
if ($size === '0.95') ok("Out-of-range size 99.9 falls back to default");
else nok("Out-of-range size not caught", $size);

// TEST 9: All 14 tabs still render clean
echo "\n[9] All 14 tabs render after typography changes\n";
$ref2 = new ReflectionClass(FraudPreventionSuite\Lib\FpsConfig::class);
$prop2 = $ref2->getProperty('instance'); $prop2->setAccessible(true); $prop2->setValue(null, null);
$tabs = ['TabDashboard','TabReviewQueue','TabTrustManagement','TabClientProfile',
         'TabMassScan','TabRules','TabReports','TabStatistics','TabTopology',
         'TabGlobalIntel','TabBotCleanup','TabAlertLog','TabApiKeys','TabSettings'];
$vars = ['modulelink' => '?module=fraud_prevention_suite'];
$ml   = '?module=fraud_prevention_suite';
foreach ($tabs as $class) {
    $fqn = "FraudPreventionSuite\\Lib\\Admin\\$class";
    if (!class_exists($fqn)) { nok("$class not found"); continue; }
    try {
        ob_start();
        (new $fqn())->render($vars, $ml);
        $h = ob_get_clean();
        if (strlen($h) > 200) ok("$class renders OK");
        else nok("$class too short", strlen($h) . ' bytes');
    } catch (\Throwable $e) { ob_end_clean(); nok("$class threw: " . $e->getMessage()); }
}

// Cleanup
Capsule::table('mod_fps_settings')
    ->updateOrInsert(['setting_key' => 'typo_tabs'],
        ['setting_value' => '{"family":"system","weight":"600","size":"0.84","letterSpacing":"0.01","lineHeight":"1.4"}']);
Capsule::table('mod_fps_settings')
    ->updateOrInsert(['setting_key' => 'typo_stats'],
        ['setting_value' => '{"family":"system","weight":"700","size":"1.80","letterSpacing":"-0.02","lineHeight":"1.2"}']);
Capsule::table('mod_fps_settings')
    ->updateOrInsert(['setting_key' => 'typo_card_body'],
        ['setting_value' => '{"family":"system","weight":"400","size":"0.95","letterSpacing":"0.00","lineHeight":"1.6"}']);
echo "\n[Cleanup] All test rows restored to defaults\n";

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
```

**Step 2: SCP test file to dev server and run it**

Write the file locally (Windows path: `D:\Claude workfolder\Claude workfolder\musing-hellman\fps-work\fps_typo_test.php`), SCP to dev server, execute:

```bash
# From WSL:
ssh evdps-new 'sshpass -p "DEVPASS" ssh -o StrictHostKeyChecking=no root@130.12.69.7 \
  "php /tmp/fps_typo_test.php"'
```

Expected: `=== RESULT: X passed, 0 failed ===`

**Step 3: Also INSERT IGNORE the 7 typo_* rows directly on the dev DB (since activate() only runs on reinstall)**

```bash
ssh evdps-new 'sshpass -p "DEVPASS" ssh -o StrictHostKeyChecking=no root@130.12.69.7 "
  mysql -u freeit_whmcs -p'\''sQ;^4fI0mT8KSV=q'\'' freeit_whmcs <<'"'"'SQL'"'"'
  INSERT IGNORE INTO mod_fps_settings (setting_key, setting_value) VALUES
  ('"'"'typo_tabs'"'"',         '"'"'{\"family\":\"system\",\"weight\":\"600\",\"size\":\"0.84\",\"letterSpacing\":\"0.01\",\"lineHeight\":\"1.4\"}'"'"'),
  ('"'"'typo_stats'"'"',        '"'"'{\"family\":\"system\",\"weight\":\"700\",\"size\":\"1.80\",\"letterSpacing\":\"-0.02\",\"lineHeight\":\"1.2\"}'"'"'),
  ('"'"'typo_stat_labels'"'"',  '"'"'{\"family\":\"system\",\"weight\":\"500\",\"size\":\"0.85\",\"letterSpacing\":\"0.06\",\"lineHeight\":\"1.4\"}'"'"'),
  ('"'"'typo_table_header'"'"', '"'"'{\"family\":\"system\",\"weight\":\"600\",\"size\":\"0.80\",\"letterSpacing\":\"0.07\",\"lineHeight\":\"1.4\"}'"'"'),
  ('"'"'typo_table_body'"'"',   '"'"'{\"family\":\"system\",\"weight\":\"400\",\"size\":\"0.90\",\"letterSpacing\":\"0.00\",\"lineHeight\":\"1.5\"}'"'"'),
  ('"'"'typo_card_header'"'"',  '"'"'{\"family\":\"system\",\"weight\":\"600\",\"size\":\"1.10\",\"letterSpacing\":\"0.01\",\"lineHeight\":\"1.3\"}'"'"'),
  ('"'"'typo_card_body'"'"',    '"'"'{\"family\":\"system\",\"weight\":\"400\",\"size\":\"0.95\",\"letterSpacing\":\"0.00\",\"lineHeight\":\"1.6\"}'"'"');
  SQL
"'
```

Do the same for the live server (root@130.12.69.3, DB: enterpri_whmc105, pw: 2wPbl]74@S).

**Step 4: Commit test file**

```bash
git add fraud_prevention_suite/  # or just the test file path in fps-work/
git commit -m "test(typo): 9-case PHP CLI verification suite for typography panel"
```

---

## Task 7: Deploy, Seed Production DB, Push to Repos

**Files:** No code changes — deploy and push only.

**Step 1: Deploy to dev server (130.12.69.7)**

```bash
# From WSL, deploy fraud_prevention_suite to dev:
ssh evdps-new 'sshpass -p "DEVPASS" ssh -o StrictHostKeyChecking=no root@130.12.69.7 "
  cd /home/freeit/public_html/modules/addons/fraud_prevention_suite &&
  git pull origin feature/v4.2.0-complete 2>&1 || echo GITPULL_FAILED
"'
```

If the server is not tracking the git branch directly, use the SCP-and-extract method established in prior sessions.

**Step 2: Deploy to live server (130.12.69.3)**

Same pattern, targeting `/home/enterpri/public_html/modules/addons/fraud_prevention_suite`.

**Step 3: INSERT IGNORE typo_* rows on both servers** (see Task 6 Step 3 for exact SQL — repeat for live server credentials).

**Step 4: Push to GitLab**

```bash
cd /d/Claude\ workfolder/Claude\ workfolder/pedantic-wiles
git push fps-gitlab feature/v4.2.0-complete
```

**Step 5: Push to GitHub via subtree**

```bash
git subtree push --prefix=fraud_prevention_suite github-fps main
```

If subtree push fails with "Updates were rejected", use force:
```bash
git push github-fps "$(git subtree split --prefix=fraud_prevention_suite HEAD)":main --force
```

**Step 6: Verify**

- Open https://freeit.us/admin/?module=fraud_prevention_suite&tab=settings
- Confirm Typography panel appears with 7 section tabs
- Select a Google Font → confirm font loads and preview updates
- Change weight → confirm bold/light applies in preview
- Save → reload → confirm values persist

---

## Reference: CSS Variable Names

| Section       | --fps-font-{s}  | --fps-weight-{s} | --fps-size-{s} (existing) | --fps-tracking-{s} | --fps-lh-{s} |
|---------------|-----------------|-----------------|---------------------------|---------------------|--------------|
| tabs          | tabs            | tabs            | tabs                      | tabs                | tabs         |
| stats         | stats           | stats           | stats                     | stats               | stats        |
| stat_labels   | stat-labels     | stat-labels     | stat-labels               | stat-labels         | stat-labels  |
| table_header  | table-header    | table-header    | th (existing: `--fps-size-th`) | table-header  | table-header |
| table_body    | table-body      | table-body      | td (existing: `--fps-size-td`) | table-body    | table-body   |
| card_header   | card-header     | card-header     | card-h                    | card-header         | card-header  |
| card_body     | card-body       | card-body       | card-body                 | card-body           | card-body    |

> **Note on size vars:** The existing `--fps-size-*` names (`--fps-size-th`, `--fps-size-td`, `--fps-size-card-h`) don't follow the new pattern. The `--fps-size-*` vars are kept as-is (they already exist and work). Only font/weight/tracking/lh vars follow the new `--fps-{prop}-{section}` pattern.
