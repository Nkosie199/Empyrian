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
1. **Data (blocking):** relink the 125 existing track posts to their albums/playlists. Start with the high-confidence lead: tracks 5329–5334 ("01 Sixes" … "06 Rawkus") are the Sixes EP (4821). The rest need the catalogue owner (CEO/artists) to confirm each album's track list; a picker page in wp-admin (below) makes this a click-through, not SQL.
2. **wp-admin relink tool** (in `plugins/mynger-integration`): lists each album/playlist with its missing track slots and a searchable picker of unlinked tracks (title, S3 artist folder, file name); saving writes the container's `post` meta.
3. **Never show an unplayable item:** Charts, Discover and search exclude containers whose playable track count is 0 (`pre_get_posts` filter in the plugin, using a cached `_empyrian_playable_count` meta refreshed on save and nightly).
4. **Player feedback:** if a play request returns no tracks, show "This release has no playable tracks yet" and leave the player's current state unchanged (no silent switch).
5. **Guard:** saving an album/playlist with a missing or deleted track id fails with an admin notice.
6. **Nightly audit:** reuse mynger-backend's station WP audit endpoint (task 117) to report any container with 0 playable tracks to the Empyrian CEO backlog.

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

## 3. Mobile

Charts rows at 375 px: Play button ≥ 44 px, title/artist truncate with ellipsis, no horizontal scroll.

## DeepDiary tasks

- #169 Relink tracks to the 12 empty releases (E-1 data, blocked on catalogue owner)
- #170 Hide unplayable releases, player feedback, guard, relink tool (E-1 code)
- #171 Listener home hub
