# Local Empyrian copy

A throwaway WordPress (WordPress Playground: PHP + SQLite on Node, no Docker or MySQL) with the live
site's theme (Muzik, from `muzik.zip` in this repo, plus our child theme) and `plugins/empyrian-home-hub`,
for testing the hub before anything touches empyrian.net.

Play Block and Loop Block are licensed and not in this repo, so `empyrian-hub-fixtures` (local only,
never install it on a real site) stands in for what the hub reads from them: the `artist` taxonomy
and releases with Play Block's meta, shaped like the live site's (albums and playlists listing their
tracks, singles with a stream, play counts), including three that must never show (draft tracks only,
no stream, empty). It also seeds Empyrian Radio's track list so the station is on air.

Unzip the parent theme once, outside the repo:

```bash
unzip muzik.zip -d ~/.sb/empyrian-local
```

Start it (Windows paths: use `--mount-dir`, not `--mount`):

```bash
MSYS_NO_PATHCONV=1 npx -y @wp-playground/cli@3.1.53 server --port=8097 --site-url=http://localhost:8097 --php=8.3 \
  --mount-dir "$HOME/.sb/empyrian-local/muzik" "/wordpress/wp-content/themes/muzik" \
  --mount-dir "<repo>/musik-child-updated/musik-child" "/wordpress/wp-content/themes/musik-child" \
  --mount-dir "<repo>/plugins/empyrian-home-hub" "/wordpress/wp-content/plugins/empyrian-home-hub" \
  --mount-dir "<repo>/tools/wp-local/empyrian-hub-fixtures" "/wordpress/wp-content/plugins/empyrian-hub-fixtures" \
  --blueprint=tools/wp-local/blueprint.json
```

The site is rebuilt from the blueprint on every start.

- http://localhost:8097/ is the hub as a visitor sees it.
- `/?empyrian_hub_test_login=hubtester` signs in as a listener with no history yet;
  `/?empyrian_hub_test_login=hubadmin` as an administrator. Both are local test users with random
  passwords; the fixture plugin signs them in.
- `node tools/wp-local/check-home-hub.mjs` checks the hub end to end: visitor and listener pages,
  the history and Hub Cards endpoints, wp-admin, saving a post, cron and the PHP log.

Play buttons do nothing locally (no Play Block); the check covers what the hub renders and records.
