# Empyrian — September 2026 QA Spec

Source: *QA Testing Notes — September Round* (25 Sep 2026).
Target: launch-ready, zero known bugs, **1 January 2027**.
Tracking: DeepDiary project **"Empyrian - CEO backlog"** (id 19).

## 1. QA finding

### E-1 Play on Charts does nothing for some songs (Medium)

**Report:** "Tapping Play on any song in the Charts section just doesn't do anything… Some play, some don't."

**Reproduced 25 Sep 2026** on `https://empyrian.net/charts/`. Clicking every `button.btn-play` in turn:

| Chart item (post id) | Play Block API `GET /wp-json/play/play/<id>` | What happens |
|---|---|---|
| KLAN4EVA (5093), 3 Mad Kings (4927), fumbled (4837), Unmixed Unmastered EP (4877), Mount Purp (2127), And Now You Die (4910), and the other album/playlist containers | `[]` | Nothing plays; the player stays on (or silently resumes) whatever was loaded before |
| The Art of Smoking Cigarettes (4802) | 1 track (MARLBORO RED) | Plays that one track |
| Single tracks (5402, 5317) | the track | Plays correctly |

**Root cause (data, not the click handler):** the album/playlist posts on Charts reference child track posts that were deleted in the WordPress-to-S3 migration. The API is correct; it has nothing to play. This is the problem documented in August as the "Empyrian Data Recovery" follow-up, still open. 12 of 13 containers are still empty; 125 track posts with working S3 audio exist but aren't linked to any container.

A second, code-side defect makes it look like a broken button: when the API returns `[]`, the player gives no feedback and keeps the previous track, so clicking KLAN4EVA loads "MARLBORO RED" paused.

**Fix:**
1. **Data (blocking, needs the catalogue owner):** relink the 125 existing track posts to their albums/playlists. Start with the high-confidence lead: tracks 5329–5334 ("01 Sixes" … "06 Rawkus") are the Sixes EP (4821). The rest need each album's track list confirmed.
2. **wp-admin relink tool:** lists each album/playlist with its missing track slots and a searchable picker of unlinked tracks (title, S3 artist folder, file name); saving writes the container's `post` meta.
3. **Hide unplayable releases (code ready, not deployed):** `wp-theme-additions/playable-releases.php` on this branch marks every post whose Play Block API returns no tracks (`_empyrian_unplayable`), keeps them out of Charts, Discover and search, shows "This release has no playable tracks yet" on the release page, and re-checks daily and on save. **Deploy:** append it to the live theme's `functions.php` (Appearance → Theme File Editor → Muzik → functions.php, after the "Mynger station sync" line), open any wp-admin page once to run the first check, then purge LiteSpeed Cache.
4. **Guard:** saving an album/playlist with a missing or deleted track id fails with an admin notice.

**Acceptance:** every Play button on `/charts/` starts the expected track (scripted check clicking each one, verifying `audio.currentSrc` changes to that release's first track and `paused == false`); the audit reports 0 empty containers.

## 2. Home hub (empyrian.net front page)

The front page becomes a listener's home, not a static theme showcase:

| Section | Content |
|---|---|
| Continue listening | Last track/album per signed-in Mynger user (SSO), resume position |
| Radio | Live/active Mynger stations with an `empyrianPostId` (mynger-backend H-3); one tap to listen |
| Charts | Top 10 playable releases (E-1 rule) |
| New releases | Latest 8 playable releases |
| For you | Releases by artists the user played or liked; falls back to Charts for new users |
| Upload | For artists: "Upload a track" (mandatory cover art flow already shipped) |

The same data feeds Hub Cards (`GET /wp-json/empyrian/v1/hub/cards`, H-2 shape) so Mynger Home's **Radio / Now playing** widget links straight here.

**Acceptance:** a signed-out visitor sees Radio, Charts and New releases with no empty sections; a signed-in user sees Continue listening after playing one track; every Play on the page works (E-1 check).

## 3. Navigation (done live, 26 Sep)

Mobile menu: the "Playlist" slot is now a centre **Upload** button (`icon-upload hide-text btn-link` → `/upload/`), matching Bonakude's centre action. Primary menu: "My Collection" and "Settings" headers carry `hide-menu-folded`, so the collapsed sidebar rail shows icons only.

## 4. Mobile

Charts rows at 375 px: Play button ≥ 44 px, title/artist truncate with ellipsis, no horizontal scroll.

## DeepDiary tasks

- #169 Relink tracks to the 12 empty releases (item 1, blocked on catalogue owner)
- #170 Deploy playable-releases.php; relink tool; save guard (items 2-4)
- #171 Listener home hub
