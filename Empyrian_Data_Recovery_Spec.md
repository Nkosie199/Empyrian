# Empyrian Data Recovery Spec — Broken Albums/Playlists

Written 3 Aug 2026, closing out the July 2026 QA session. This is a follow-up
to Bug 2 in `July_2026_QA_Bugfixes.md` — the console-crash part of that bug
is fixed and live; this document covers the deeper data problem found while
verifying that fix, which is not a code bug and was not resolved this
session.

## Problem

Every album/playlist container post on Empyrian references child track
posts that no longer exist in WordPress. `GET /wp-json/play/play/<id>` for
any of these containers returns `[]` — the API is working correctly, the
referenced content is simply gone. No amount of further code changes to
Play Block fixes this; the tracks it's being asked to play don't exist.

## Scope — confirmed, not a sample

All 13 album/playlist containers on the site are affected, 100% broken,
zero exceptions:

| ID | Title | Type | Tracks needed |
|---|---|---|---|
| 5134 | Phil-Air – Faith Takes | album | 6 |
| 5093 | KLAN4EVA | playlist | 11 |
| 4927 | C_MO x Ville x Phil-Air – 3 Mad Kings | playlist | 3 |
| 4910 | Phil-Air – And Now You Die | album | 7 |
| 4877 | LaPartyX – Unmixed Unmastered EP | playlist | 8 |
| 4855 | Phil-Air – Freedom of Jail EP | playlist | 4 |
| 4837 | Goofy – fumbled | album | 7 |
| 4821 | Deelow & Goofy – Sixes EP | playlist | 6 |
| 4802 | Vanity Of Man & Baba Black – The Art of Smoking Cigarettes | album | 4 |
| 4792 | Vanity Of Man – Pagne Loves Vanity | album | 6 |
| 4790 | Deelow – Planet 2.0 | playlist | 11 |
| 4615 | KLAN – Promo Tape | playlist | 7 |
| 2127 | KLAN – Mount Purp (Mixtape) | album | 21 |

Total: 101 track slots needed across 13 containers. Each container stores
its track list as a comma-separated list of child post IDs in its own
`post` postmeta; every single ID in every list points to a post that has
been permanently deleted (confirmed via `wp-admin/post.php?post=<id>&action=edit`
returning "you attempted to edit an item that does not exist" for every
one checked). The containers' own `items` postmeta (a possible cached
fallback) is empty on all 13 — there is no surviving record anywhere in
the database of what the original track lists were.

## What data does exist

125 individual track posts currently exist on the site, all with real,
working audio at `stream`/`stream_url` pointing to Mynger's S3 bucket
(`myngerbucket14bd7-main.s3.eu-west-1.amazonaws.com`). These are very
likely the same tracks that used to fill the 13 containers above, migrated
in from the old WordPress-hosted setup — but their post IDs no longer
match what the containers expect, and nothing on the post itself says
which container it used to belong to.

The S3 path sometimes encodes an artist name as a folder segment
(`users/<artist>/public/<file>.mp3`), which is the only usable signal
found:

| S3 folder | Track count |
|---|---|
| phil_air | 7 |
| klan | 23 |
| gatsheni | 1 |
| administrator (generic, no artist) | 94 |

## Why this can't be automatically reconstructed

The numbers don't support a clean 1:1 rebuild even where an artist tag
exists. Phil-Air alone needs 20 track slots across 4 containers (Faith
Takes, And Now You Die, Freedom of Jail EP, 3 Mad Kings) but only 7
Phil-Air-tagged tracks exist. KLAN needs 39 slots across 3 containers but
only 23 KLAN-tagged tracks exist. The remaining containers (Deelow, Goofy,
Vanity Of Man, LaPartyX, C_MO x Ville) have no artist-folder signal at all
— their tracks, if present, are indistinguishable inside the 94-track
"administrator" bucket, which has no tag connecting any track to any
specific album. Guessing an assignment risks putting the wrong artist's
songs into someone else's album on a live, public site.

## Full track list

All 125 unlinked track posts (ID, title, S3 artist-folder tag where
present) are in `empyrian_unlinked_tracks.csv` in this same folder, for
cross-referencing against whatever source of truth turns up.

One strong lead worth checking first: tracks 5329–5334 are titled
"01 Sixes", "02 Chose Us", "03 Sacreligious", "04 Stupid", "05 Gold Mine",
"06 Rawkus" — six sequentially numbered tracks, the first literally titled
"Sixes." This is very likely the missing tracklist for container 4821,
"Deelow & Goofy – Sixes EP," which needs exactly 6 tracks. High confidence,
but still needs a human to confirm before writing it back — not applied
automatically per the reasoning above.

A second, weaker lead: tracks 5344–5350 are a numbered 7-track sequence
("1) rodent...", "3) below the planet...", "7) the session..."
featuring dylan budhoo, kenzo, alpha g, deelow) — track 3's title
mentioning "the planet" is suggestive, but none of the 13 known containers
need exactly 7 tracks with a title matching this project, so this may
belong to a container not in the current broken-13 list, or to nothing
recoverable. Flagged for manual review, not assumed.

## Recommended paths forward (pick one, in order of preference)

1. **Find a real source of truth.** Ask whoever ran the WordPress-to-S3
   migration whether there's an export, spreadsheet, or the original S3
   folder structure organized by album (as opposed to the flat
   `users/<artist>/public/` layout currently visible) that maps track
   files to their album/playlist. This is the only path that guarantees
   correct results.
2. **Manual curation.** Have someone who knows the actual catalog go
   through the 13 containers one at a time in wp-admin, using the 125
   existing track titles as the picklist, and manually assign the correct
   posts to each container's `post` meta field. Slow but accurate.
3. **Best-effort by filename/track-number pattern.** Some "administrator"
   tracks have sequential numbered filenames (e.g. `06-Wakanda.mp3`,
   `07-Stratosphere.mp3`), suggesting they came from a specific album in
   order. This could narrow candidates for containers with a matching
   track count, but still requires a human to confirm the specific album
   match — not safe to automate blind.
4. **Accept the loss and clean up.** If the original files/records are
   genuinely unrecoverable, unpublish or delete the 13 broken container
   posts so Charts/Discover stop showing dead, unplayable entries, and
   let the underlying 125 tracks stand alone until (if ever) they're
   re-grouped.

## How to apply a fix once track IDs are known

Once the correct list of child post IDs is known for a given container,
update its `post` postmeta via the REST API (same method used elsewhere
this session):

```js
const nonce = wpApiSettings.nonce;
await fetch(`/wp-json/wp/v2/posts/<container_id>`, {
  method: 'POST',
  credentials: 'include',
  headers: {'X-WP-Nonce': nonce, 'Content-Type': 'application/json'},
  body: JSON.stringify({meta: {post: '<id1>,<id2>,<id3>,...'}})
});
```

Then verify via `GET /wp-json/play/play/<container_id>?type=post` — it
should return populated track data instead of `[]` — and confirm actual
playback on `/charts/` or wherever the container is surfaced.

## Status

Not fixed. Left open pending a decision from the user on which
remediation path to take. No further automated attempts should be made
without a real source of truth — the risk of shipping wrong track
groupings to a live site outweighs the benefit of a partial guess.
