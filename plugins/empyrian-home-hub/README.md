# Empyrian Home Hub

Turns empyrian.net's front page into a listener's home. Only the front page's content changes; the
theme's header, sidebar and player stay.

| Section | Shows | Who |
|---|---|---|
| Continue listening | The last tracks played, newest first; Play resumes where they stopped | Signed in, after one play |
| Empyrian Radio | The station's own player (one tap to listen live) and a link to the programme guide | Everyone, while the station is on air |
| Charts | Top 10 playable releases by plays | Everyone |
| New releases | Latest 8 playable releases | Everyone |
| For you | Releases by artists the listener played or liked; new listeners get the releases just below the Charts | Signed in |
| Upload | "Upload a track" | Everyone |

A release is playable when Play has something to play (the E-1 rule): a single with a stream, or an
album/playlist with at least one published track that has a stream. Releases are read from Play
Block's post meta (`type`, `post`, `stream`, `post-count-all`), never through Play Block's REST
callbacks, which echo and exit when called in-process. The release lists are cached for 10 minutes
and refreshed whenever a post is saved or deleted.

Plays and likes are recorded for signed-in listeners only, on their WordPress user
(`empyrian_hub_played`, `empyrian_hub_liked`), from Play Block's `played` and `like.play` events.
Uninstalling the plugin deletes them.

The same sections are served as Hub Cards (mynger-backend H-2 shape) at
`GET /wp-json/empyrian/v1/hub/cards`, so Mynger Home can link straight here. Signed-out requests
get Radio, Charts, New releases and Upload, cacheable for a minute.

## Test

```bash
php plugins/empyrian-home-hub/tests/run.php
node tools/wp-local/check-home-hub.mjs
```

The second needs the local copy running: `tools/wp-local/README.md`.

## Deploy

1. Back up first: over SFTP or the Hostinger File Manager, note that `wp-content/plugins/empyrian-home-hub`
   does not exist yet (this plugin replaces nothing).
2. Upload the `empyrian-home-hub` folder to `wp-content/plugins/` over SFTP or the File Manager (not
   the wp-admin plugin editor), then activate it under Plugins.
3. Check https://empyrian.net/ signed out (Radio, Charts, New releases, Upload; every Play plays),
   then signed in: play a track for a few seconds, reload, and Continue listening appears.

## Roll back

Deactivate it under Plugins, or, if wp-admin is unreachable, delete or rename
`wp-content/plugins/empyrian-home-hub` over SFTP or the File Manager. The front page then shows
its own content again.
