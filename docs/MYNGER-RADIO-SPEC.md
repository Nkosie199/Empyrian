# Mynger Radio on Empyrian — spec

Status: implemented 2026-10-17/18 in the `musik-child` theme (PR #6); live at `/radio` and `/radio/embed`. DeepDiary task 491 is Done.

## 1. Why

Mynger's Home is "the DJ of Mynger": someone who doesn't know what to listen to presses one button and music starts. Empyrian does the heavy lifting. It runs one always-on station, **Mynger Radio**, and exposes its own player so Mynger (and anything else we own) can embed it. Mynger never gets a player of its own.

## 2. What exists today (checked 2026-10-11)

- Empyrian is WordPress with the Musik theme and the Play Block plugin. Play Block already plays a queue and serves track data:
  - `GET /wp-json/play/play/items` lists 141 playable items as `id:title`.
  - `GET /wp-json/play/play/{id}` returns `stream_url` (S3 MP3s in `myngerbucket14bd7-main`), `artwork_url`, `title`, `user`, `type: audio`, `provider: html5`. Albums return an array of tracks.
  - `duration` is empty on the tracks sampled, so it has to be backfilled (section 5.2).
- Our own plugin `plugins/mynger-integration` already handles Mynger tokens and S3 uploads. SSO is in place (see `MYNGER-SSO-SETUP.md`).
- Mynger's Home already shows the latest Play Block tracks as "Now playing" and in its hero (mynger-backend `PlayBlockCatalog`); this spec replaces that with the real station.
- `empyrian_unlinked_tracks.csv` and `Empyrian_Data_Recovery_Spec.md` list tracks whose files are missing. Those must not reach the station.

## 3. Rules

- All new code goes in **our own plugin** (`plugins/empyrian-station`, or a module of `mynger-integration`). Never edit Musik, Play Block or any third-party plugin files, and never use wp-admin's theme or plugin editor.
- Never call another plugin's REST callback in-process (`rest_do_request` on Play Block routes echoes and exits; it took Empyrian's wp-admin down on 2026-09-26). Read Play Block's data from post meta or over HTTP.
- Test on the local docker copy (`docker/`) on every hook the code runs on: front end, `admin_init`, `save_post`, cron, REST. Before deploying, back up the files being replaced and confirm a rollback through Hostinger File Manager / SFTP that doesn't need wp-admin.

## 4. The station

### 4.1 One shared clock

Everyone tuned in hears the same track at the same moment, like real radio. The schedule is deterministic: a playlist of tracks with known durations, starting from a fixed station epoch. "Now" is computed as `(now - epoch) mod playlist length`, so no audio server or streaming infrastructure is needed. The player loads the current track's `stream_url` and seeks to the offset.

### 4.2 Building the playlist

A cron job (hourly, plus whenever a track is published or updated) rebuilds the next 24 hours from eligible tracks:

- **Eligible**: published, `type` audio, a reachable `stream_url` (HEAD 200), a known duration of at least 60 s, not on the unlinked list, and the per-track **Radio eligible** flag on. The flag defaults on for tracks uploaded by their artist and off for imports; admins can toggle it in the track's sidebar.
- **Rotation**:
  - No artist twice within 45 minutes and no track twice within 4 hours.
  - New uploads (under 14 days old) get double weight.
  - Liked tracks (Play Block `like_count`) get up to 1.5x weight.
- **Station idents**: an optional short "You're listening to Mynger Radio" clip every 4 tracks, uploaded in the settings page.
- If fewer than 10 tracks are eligible, the station reports `offAir` instead of looping a handful of songs.

The built schedule is stored in an option (or a small custom table if it grows), so reads never rebuild it.

### 4.3 Settings page (Tools → Mynger Radio)

On/off, station name and artwork, ident clip, rotation numbers from 4.2, a "rebuild now" button, the next 20 scheduled tracks, and a list of excluded tracks with the reason each one is excluded.

## 5. What Empyrian exposes

### 5.1 REST (public, cacheable for 15 s)

- `GET /wp-json/empyrian/v1/radio/now` returns `{ station, offAir, track: { id, title, artist, artworkUrl, streamUrl, duration, uri }, startedAt, offsetSeconds, next: [3 tracks] }`.
- `GET /wp-json/empyrian/v1/radio/schedule?hours=6` returns the upcoming slots.
- `artworkUrl` must be at least 600 px (use the full image, not the `-150x150` thumbnail Play Block returns).

### 5.2 Duration backfill

A one-off WP-CLI command plus a `save_post` hook reads each track's duration and stores it in Play Block's own `duration` meta, which today is empty. It uses `wp_read_audio_metadata` for local files, or a ranged GET for S3 files. Tracks whose duration can't be read are excluded and listed in the settings page.

### 5.3 The embeddable player

`https://empyrian.net/radio/embed` is a chrome-less page that uses **Play Block's own player**, not a new one. It has:

- Artwork, title, artist, a big play/pause button, a "Next up" line and a link to the track on Empyrian.
- A layout that works from 320 px up, with touch targets sized by padding, labels as well as icons, and nothing to learn. Children and older users should see one obvious button.
- Light and dark versions (`?theme=dark`) using Empyrian's colours from Ringer's `brand_colours.php`: purple primary, amber and pink accents.
- `?track={id}` to embed a single track instead of the station.
- Headers: `Content-Security-Policy: frame-ancestors https://mynger.com https://*.mynger.com http://localhost:3000`. No `X-Frame-Options: DENY` on this route.
- Media Session metadata, so phone lock screens and headphone buttons work.
- An autoplay rule: browsers block sound until the user interacts. The embed starts muted-ready and plays on the first tap, either inside the iframe or through the `play` message from the host, which is called from a user gesture.

### 5.4 The Mynger Station Embed contract (shared with Bonakude TV)

Messages go over `window.postMessage`. The embed accepts commands only from the allowed origins in 5.3, and posts events to `window.parent` with the host's origin.

| Direction | Message | Payload |
|---|---|---|
| host → embed | `mynger:station:play` / `pause` / `next` | none |
| host → embed | `mynger:station:volume` | `{ volume: 0..1 }` |
| embed → host | `mynger:station:ready` | `{ station, version: 1 }` |
| embed → host | `mynger:station:state` | `{ playing, offsetSeconds, volume }` |
| embed → host | `mynger:station:nowPlaying` | the `track` object from 5.1 |
| embed → host | `mynger:station:error` | `{ code, message }` (`offAir`, `blocked`, `network`) |

## 6. What Mynger does (small, separate task)

- Mynger keeps one hidden or mini iframe of `/radio/embed` mounted in its app shell, so playback continues across pages. The mini bar shows `nowPlaying` and play/pause, and sends contract messages only. Mynger has no audio element.
- Home's "Now playing" tile and the DJ hero read `/radio/now` and start the station with `mynger:station:play`.
- "Open on Empyrian" deep links to the track `uri`, and SSO signs the user in there.

## 7. Done when

1. `/radio/now` returns the same track and an offset within 2 s for two different browsers.
2. Pressing play in Mynger starts Mynger Radio within 2 s, keeps playing while the user moves between Mynger pages, and shows on the phone lock screen.
3. No ineligible, unreachable or duration-less track is ever scheduled, and the settings page shows why each excluded track is excluded.
4. The embed passes axe at 320, 768 and 1440 px in light and dark, has no horizontal scroll, and every control has a visible label.
5. Tested on the local docker copy on every hook, with a backup and a rollback path recorded before going live.

## 8. Open questions for the owner

1. Station name: "Mynger Radio" or "Empyrian Radio"?
2. Should tracks imported for other artists (not uploaded by the artist) be eligible by default? Rights matter here.
3. Do we want shows or time slots (for example "Amapiano at 6") later? The schedule model allows it, but it's out of scope for v1.
