# July 2026 QA Bugfixes — Empyrian (empyrian.net)

Source: Monthly QA Testing Report, July 2026, section 2 "Empyrian" (3 findings).

**Repo context:** WordPress, purchased "Musik" theme + near-empty child theme (`musik-child.zip`). Tier 2 OAuth "Sign in with Mynger" client per `docs/MYNGER_SSO_PATTERN.md`.

**Status:** All 3 QA bugs fixed and confirmed live (2 Aug 2026): Bug 1 (sign-in divider), Bug 2 (console crash — code fixed; underlying data-loss issue is not a code fix, see below), Bug 3 (song list spacing).

**Separate item, not from the QA report:** content was migrated from being hosted directly in WordPress to Mynger's S3 bucket, which left ~125 posts authored by the `claude` WP account (claude.superai@gmail.com) instead of the real owner. Reassigned all 125 posts to the Administrator account (myngerptyltd@gmail.com) via the REST API on 3 Aug 2026 — verified 0 posts remain under `claude` across every post status. Still open: whether any content needs relinking to the correct S3 source (flagged by the user, not yet investigated — the deleted-child-track issue in Bug 2 above may or may not be related to this same migration).

## Bug 2 — Play button in Charts section does not respond (HIGH — fixed)
- **Replicated:** yes, live (2 Aug 2026). On `/charts/`, clicking a track's play control opens the persistent bottom player bar, but playback never starts (stays at 00:00, play icon never flips to pause).
- **Actual root cause (confirmed by reading plugin source via wp-admin Plugin File Editor, not just the console trace):** Play Block v13.2 bundles a vendored library at `wp-content/plugins/play-block/build/libs/plyr/plyr.playlist.js`. Its `timeupdate` handler unconditionally reads `t.item.start` / `t.item.end` on every tick. `t.item` is briefly `undefined` during a track click/switch (before the new item is assigned), so the handler throws, which aborts playback — matches the console trace exactly (`HTMLAudioElement` timeupdate handler, not a missing post-meta field as first hypothesized).
- **Fix applied (2 Aug 2026):** one-line defensive guard added at the top of that `timeupdate` handler in `plyr.playlist.js`:
  - Before: `this.player.on("timeupdate",(function(i){t.timer++,...`
  - After: `this.player.on("timeupdate",(function(i){if(!t.item)return;t.timer++,...`
  - **Rollback (this file has no git history — no other rollback path exists):** in wp-admin → Plugins → Plugin File Editor → Play Block → `build/libs/plyr/plyr.playlist.js`, find `if(!t.item)return;` immediately after `this.player.on("timeupdate",(function(i){` and delete just that substring, then Update File.
- **Test plan:** Charts page, click play on 2–3 different tracks; confirm audio starts, the player bar timer advances past 00:00, and Chrome console shows zero exceptions from `play-block`.
- **Note:** this patches the plugin's vendored library file directly. A future Play Block plugin update will silently overwrite this fix — re-check after any Play Block update.

- **Second bug found during live verification (2 Aug 2026) — ROOT CAUSE CONFIRMED, not a code bug:** after the crash fix, clicking play still doesn't start audio. Traced via direct REST calls (`GET /wp-json/play/play/<id>?type=post`):
  - Most Charts entries are "playlist"-type container posts. Each stores its track list as a CSV of child post IDs in its own `post` postmeta (e.g. post 4790 "Deelow – Planet 2.0" lists `4753,4756,4758,4760,4762,4764,4766,4768,4770,4772,4774`).
  - Every one of those 11 child post IDs has been permanently deleted from WordPress (`wp-admin/post.php?post=4753&action=edit` → "You attempted to edit an item that does not exist"). `get_play_item()` in `class-play-api.php` correctly returns `false` for a nonexistent post — there is no bug in that function — so the playlist's `$data` array stays empty and the API legitimately returns `[]`.
  - **Confirmed this is site-wide, not a one-off**: sampled 7 Charts entries (4792, 4802, 4821, 4855, 4910, 4927, 5134) — only 1 of 7 (4802) returns real track data. The other 6 all have the same broken/deleted-child-post pattern.
  - **This is data loss, not a defect in Play Block's code.** The actual audio tracks were deleted from WordPress at some point; there's no code patch that can play a track whose post no longer exists.
- **Confirmed 3 Aug 2026: all 13 album/playlist containers on the site are affected, not just the 6 sampled here — 100% broken.** Full scope, data findings, and recommended remediation paths are written up in `Empyrian_Data_Recovery_Spec.md`. Not fixed; needs a decision from the user before any further action.
- **Test plan (once track data is restored):** Charts page, click play on 2–3 previously-broken tracks; confirm `window.playlist.items.length` increases, `#player` gets a real `src`, audio audibly plays, and the bottom bar timer advances past 00:00.

## Bug 1 — Two sign-in methods may confuse users (Low)
- **Replicated:** yes — `/login/` shows a "Sign in with Mynger" OAuth button directly above a full native WordPress username/password form, both equally weighted with no visual separation (no "or" divider, no de-emphasis of one path).
- **Proposed fix:** add a visual divider ("— or sign in with email —") between the two, and/or make "Sign in with Mynger" the visually primary action (Tier 2 OAuth is the intended cross-app path per `MYNGER_SSO_PATTERN.md`) with the native form collapsed behind a "use email instead" toggle.
- **Test plan:** visual review on `/login/` at both desktop and mobile widths after the change; confirm both paths still functionally work (native WP login + OAuth redirect round-trip).

## Bug 3 — Song list is visually compact (Low)
- **Not deeply investigated** — cosmetic CSS-only ask (increase row spacing/padding on the Charts/song-list template).
- **Proposed fix:** same child-theme-CSS-override approach as Bonakude's login page. Target file: Musik parent theme's chart/track-list template CSS class (needs locating once implementation is approved — `muzik.zip` wasn't unpacked for this specific class this session).
- **Test plan:** visual diff (row height/padding) on `/charts/` before and after.
