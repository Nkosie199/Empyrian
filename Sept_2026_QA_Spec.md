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

**Root cause:** the album/playlist posts pointed at track posts deleted in the WordPress-to-S3 migration, so the Play Block API returned nothing for them.

**Fixed live (27 Sep 2026):** all 13 albums/playlists relinked to their existing track posts (matched by cover art, filename numbering, the album descriptions' tracklists and the local `Music/KLAN` library, confirmed by the CEO), and the 9 unlinked REALapse tracks published as a new release, **Deelow – The REALapse EP** (post 5574). Every Play button on `/charts/` was re-tested and plays its own first track.

Previous `post` meta values (all pointing at deleted posts), kept for the record:

| Post | Release | Old track IDs | New track IDs |
|---|---|---|---|
| 2127 | Mount Purp | 4321,4369…4407 (21) | 5280,5281,5282,5283,5284,5279,5285…5299 |
| 4821 | Sixes EP | 4807,4809,4811,4815,4817,4819 | 5329–5334 |
| 4802 | The Art of Smoking Cigarettes | 4794,4796,4798,4800 | 5341,5342,4798,5343 |
| 4792 | Pagne Loves Vanity | 4778–4788 | 5335–5340 |
| 4910 | And Now You Die | 4879,4896…4908 | 5363–5369 |
| 4877 | Unmixed Unmastered EP | 4858…4875 | 5351–5358 |
| 4837 | fumbled | 4823…4835 | 5344–5350 |
| 4927 | 3 Mad Kings | 4913,4923,4925 | 5370,5372,5371 |
| 4790 | Planet 2.0 | 4753…4774 | 5322,5320,5323,5321,5327,5325,5318,5324,5326,5319,5328 |
| 4615 | Promo Tape | 2324,4603…4613 | 5306,5304,5308,5305,5307,5303,5302 |
| 5093 | KLAN4EVA | 5066…5090 | 5393,5395,5391,5396,5390,5400,5392,5399,5394,5398,5397 |
| 5134 | Faith Takes | 5121…5132 | 5401–5406 |
| 4855 | Freedom of Jail EP | 4839,4849,4851,4853 | 5359,5360,5361,5362 |

**Remaining (prevention):** an `empyrian-playable` plugin (in this repo, installed via Plugins → Upload, never theme/plugin file edits) that hides any release whose track list resolves to no published tracks and blocks saving one. It must read the `post` meta directly; calling the Play Block REST route in-process echoes JSON and exits, which took down wp-admin on 26 Sep. Tested on the local docker copy first.

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

## 3. Done live (26–27 Sep)

- Mobile bottom bar: centre **Upload** button (`icon-upload hide-text btn-link` → `/upload/`), matching Bonakude.
- Collapsed desktop sidebar: "My Collection" and "Settings" headers hidden (`hide-menu-folded`).
- Android app (`empyrian-native` branch `feature/target-sdk-36`, 1.0.3 / versionCode 5): target SDK 36, WebView inside the safe area, dark status bar icons, CI builds APK + AAB. Signed AAB built and emulator-tested.

## 4. Performance (Bonakude parity)

Empyrian answers in ~1.15 s (Hostinger CDN, no page-cache hit); Bonakude answers in ~0.16 s behind Cloudflare. Move empyrian.net's nameservers to Cloudflare and apply `docs/CLOUDFLARE.md` (cache bypass for wp-admin, login, members, OIDC). Needs the Cloudflare account and the Hostinger domain panel.

## 5. Mobile

Charts rows at 375 px: Play button ≥ 44 px, title/artist truncate with ellipsis, no horizontal scroll.

## DeepDiary tasks

- #170 empyrian-playable plugin (prevention)
- #171 Listener home hub
- #177 Cloudflare for empyrian.net
