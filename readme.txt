=== ACSS Recipe Drawer ===
Contributors: stephenjeffers
Tags: acss, automaticcss, builderius, recipes, css
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Renders a shadow-DOM recipe drawer in the Builderius canvas that exposes ACSS built-in recipes plus your own custom recipes, with auto-unwrapped CSS ready to paste into the CSS IDE.

== Description ==

ACSS Recipe Drawer is a standalone companion plugin for Builderius users who also run Automatic.css (ACSS). It adds a small, isolated drawer to the bottom-right of the Builderius canvas iframe. Type a recipe name, pick from the autocomplete list, and copy the unwrapped CSS straight into a Builderius CSS selector.

* **Shadow-DOM isolation** — the drawer lives inside a closed shadow root, so ACSS resets and Builderius styles cannot reach it and it cannot leak into the canvas.
* **Built-in + custom recipes** — ships with ACSS's ~109 built-in recipes (Expansions). Add your own under *Settings → ACSS Recipe Drawer*; custom recipes override built-ins on name collision.
* **Automatic `%root%` unwrap** — ACSS recipes use a `%root%` token as a selector placeholder. The drawer resolves it for you:
  * A single plain `%root% { ... }` wrapper is stripped and the inner block is dedented, leaving bare declarations.
  * Any other arrangement (`%root% > *`, `:has(> %root%)`, `.target %root% a::after`, etc.) is converted to CSS-nesting syntax using `&`, ready to paste inside a selector in the CSS IDE.
  * `%root%` inside comments is left untouched.
* **`?` syntax tolerance** — type `?primary-clr;` with a leading `?` and trailing `;` and it still resolves, matching ACSS's Bricks/Gutenberg muscle memory.

= Context =

The drawer only renders inside the Builderius canvas iframe (`?builderius_inner_preview`), only for logged-in users with the `builderius-development` or `manage_options` capability, and only when the ACSS API class is present. It is read-only with respect to Builderius: no GraphQL mutations, no VCS commits, no branch writes.

== Installation ==

1. Upload the `acss-recipe-drawer` folder to `wp-content/plugins/`.
2. Activate **ACSS Recipe Drawer** from the Plugins screen.
3. Open a Builderius template — the "ACSS Recipes" tab appears at the bottom-right of the canvas.
4. (Optional) Add custom recipes under *Settings → ACSS Recipe Drawer*.

== Frequently Asked Questions ==

= Why is the drawer in the canvas and not the outer editor? =

The canvas is where you preview styles, and the CSS IDE lives in the outer frame. Copy from the canvas drawer, paste into the outer-frame IDE. This was an explicit design choice; moving to the outer frame later is a contained change.

= The Copy button did not flash. What happened? =

The Clipboard API requires a secure context and can be blocked inside an iframe without `allow="clipboard-write"`. The drawer falls back to a legacy `execCommand('copy')` path. If both fail, the output box is a real textarea — select and copy manually.

= Does this modify the existing ACSS Builderius Bridge plugin? =

No. This is a separate plugin. It depends only on ACSS's PHP API (`\Automatic_CSS\API::get_all_recipes()`), not on the bridge.

== Changelog ==

= 1.0.0 =
* Initial release. Shadow-DOM drawer in the Builderius canvas, built-in + custom recipes, two-tier `%root%` unwrap, autocomplete with keyboard navigation, clipboard copy with fallback.
