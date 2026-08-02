# July 2026 QA Bugfixes — Empyrian (empyrian.net)

Source: Monthly QA Testing Report, July 2026, section 2 "Empyrian" (3 findings).

**Repo context:** WordPress, purchased "Musik" theme + near-empty child theme (`musik-child.zip`). Tier 2 OAuth "Sign in with Mynger" client per `docs/MYNGER_SSO_PATTERN.md`.

**Status: specced only, not implemented.** Nothing in this repo has been changed.

## Bug 2 — Play button in Charts section does not respond (HIGH — root cause found)
- **Replicated:** yes, live (2 Aug 2026), with the exact root cause captured. On `/charts/`, clicking a track's play control opens the persistent bottom player bar, but playback never starts (stays at 00:00, play icon never flips to pause).
- **Root cause (confirmed via Chrome console):**
  ```
  [EXCEPTION] wp-content/plugins/play-block/build/play.min.js?ver=13.2:1:254605
  TypeError: Cannot read properties of undefined (reading 'start')
      at HTMLDivElement.<anonymous> (play.min.js:1:254605)
      at tt.ie (play.min.js:1:294334)
      at HTMLAudioElement.<anonymous> (play.min.js:1:346337)
  ```
  The "Play Block" WordPress plugin (v13.2) throws reading `.start` off an undefined object inside its `HTMLAudioElement` handler — almost certainly a per-track cue-point/preview-clip-start field the plugin expects on the track's post meta that isn't set for these particular Charts tracks, aborting the whole `play()` call before audio actually starts.
- **Proposed fix (in priority order, no plugin code needed for the first two):**
  1. Open one of the affected tracks (e.g. "C_MO x Ville x Phil-Air – 3 Mad Kings") in wp-admin and check the Play Block's own track/attachment fields for a start-time/preview-clip setting; populate it if blank.
  2. Check for a Play Block plugin update — changelog may already fix this (currently pinned at v13.2 per the querystring version).
  3. If neither resolves it, a minimal defensive patch in the child theme's JS (enqueued after `play.min.js`) guarding the specific handler, or swap Charts to a different/simpler audio block as a stopgap.
- **Test plan:** repeat the exact repro (Charts page, click play on 2–3 different tracks); confirm audio starts, the player bar timer advances past 00:00, and Chrome console shows zero exceptions from `play-block`.
- **Cross-check flagged (unconfirmed):** Bonakude's own docs (`FINDINGS-2026-05-30.md`) separately flag "Play Block" as one of the plugins implicated in an earlier Bonakude slowness issue — worth checking whether Bonakude's video player shares any related symptom, since it may be the same plugin across both WP properties.

## Bug 1 — Two sign-in methods may confuse users (Low)
- **Replicated:** yes — `/login/` shows a "Sign in with Mynger" OAuth button directly above a full native WordPress username/password form, both equally weighted with no visual separation (no "or" divider, no de-emphasis of one path).
- **Proposed fix:** add a visual divider ("— or sign in with email —") between the two, and/or make "Sign in with Mynger" the visually primary action (Tier 2 OAuth is the intended cross-app path per `MYNGER_SSO_PATTERN.md`) with the native form collapsed behind a "use email instead" toggle.
- **Test plan:** visual review on `/login/` at both desktop and mobile widths after the change; confirm both paths still functionally work (native WP login + OAuth redirect round-trip).

## Bug 3 — Song list is visually compact (Low)
- **Not deeply investigated** — cosmetic CSS-only ask (increase row spacing/padding on the Charts/song-list template).
- **Proposed fix:** same child-theme-CSS-override approach as Bonakude's login page. Target file: Musik parent theme's chart/track-list template CSS class (needs locating once implementation is approved — `muzik.zip` wasn't unpacked for this specific class this session).
- **Test plan:** visual diff (row height/padding) on `/charts/` before and after.
