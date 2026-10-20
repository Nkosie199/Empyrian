# Empyrian end-to-end tests

Cypress regression suite that runs against the live site, https://empyrian.net.

## Setup

```bash
cd tests/e2e
npm install
```

## Run

- `npm run test:public`: read-only checks anyone can run. Navigation, categories, playback, search and the phone layout.
- `npm run test:member`: signs in with Mynger and uploads, publishes, edits, makes private and removes a test upload,
  checking the file appears in and disappears from the member's own S3 folder. Copy `cypress.env.example.json` to
  `cypress.env.json` (git-ignored) and fill in a non-admin Mynger test account first.
- `npm test`: both. `npm run open` for the interactive runner.

The member suite cleans up after itself. The upload fixture is a CC0 piano-note MP3 (archive.org).

Cloudflare caches pages for a while, so a check can briefly fail just after content is edited in wp-admin.
