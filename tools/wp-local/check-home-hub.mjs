// End-to-end check of empyrian-home-hub against the local copy: node tools/wp-local/check-home-hub.mjs [base-url]
import assert from 'node:assert/strict';

const base = process.argv[2] ?? 'http://localhost:8097';

function session() {
  const cookies = new Map();
  const request = async (path, init = {}) => {
    const response = await fetch(`${base}${path}`, {
      redirect: 'manual',
      ...init,
      headers: { ...(init.headers ?? {}), Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; ') },
    });
    for (const cookie of response.headers.getSetCookie()) {
      const [pair] = cookie.split(';');
      const at = pair.indexOf('=');
      cookies.set(pair.slice(0, at), pair.slice(at + 1));
    }
    return response;
  };
  return { request, page: async (path = '/') => (await request(path)).text() };
}

function sections(html) {
  return [...html.matchAll(/<section class="empyrian-hub__section[^"]*" aria-labelledby="empyrian-hub-([a-z-]+)">([\s\S]*?)<\/section>/g)].map((match) => ({
    key: match[1],
    html: match[2],
    ids: [...match[2].matchAll(/<li class="empyrian-hub__item" data-play-id="(\d+)"/g)].map((li) => Number(li[1])),
  }));
}

const section = (all, key) => all.find((candidate) => candidate.key === key);
const keys = (all) => all.map((candidate) => candidate.key);
const nonceOf = (html) => html.match(/"nonce":"([a-f0-9]+)"/)[1];

const fixtures = await (await fetch(`${base}/wp-json/empyrian-hub-fixtures/v1/expected`)).json();
const byPlays = [...fixtures.releases].sort((a, b) => b.plays - a.plays).map((release) => release.id);
const byDate = [...fixtures.releases].sort((a, b) => a.age - b.age).map((release) => release.id);
const releasesBy = (artists) => [...fixtures.releases].filter((release) => artists.includes(release.artist)).sort((a, b) => a.age - b.age).map((release) => release.id);
const playable = new Set([...fixtures.releases.map((release) => release.id), ...Object.values(fixtures.tracksBy).flat()]);

const visitor = session();
const front = sections(await visitor.page());
assert.deepEqual(keys(front), ['radio', 'charts', 'new', 'upload']);
assert.deepEqual(section(front, 'charts').ids, byPlays.slice(0, 10));
assert.deepEqual(section(front, 'new').ids, byDate.slice(0, 8));
assert.match(section(front, 'radio').html, /class="empyrian-radio-frame empyrian-hub__radio-player"/);
assert.match(section(front, 'upload').html, /href="[^"]*\/upload\/"/);
for (const { ids } of front) assert.ok(ids.every((id) => playable.has(id)), `unplayable item in ${ids}`);
assert.ok(front.every(({ ids }) => fixtures.unplayable.every((id) => !ids.includes(id))));
console.log('ok   signed out: Radio, Charts (by plays), New releases (by date), Upload; only playable releases');

const frontHtml = await visitor.page();
assert.ok(!frontHtml.includes('THEME SHOWCASE CONTENT'), 'the hub replaces the front page content');
assert.ok(!frontHtml.includes('"nonce"'), 'no REST nonce for visitors');
const charts = await visitor.page('/charts/');
assert.ok(charts.includes('Charts page') && !charts.includes('class="empyrian-hub"'), 'other pages keep their content');
assert.ok(charts.includes('empyrian-home-hub/assets/hub.js'), 'tracker loads on every page');
console.log('ok   only the front page changes; tracker loads everywhere');

const publicCards = await visitor.request('/wp-json/empyrian/v1/hub/cards');
assert.equal(publicCards.headers.get('cache-control'), 'public, max-age=60');
const cards = await publicCards.json();
assert.deepEqual(cards.map((card) => card.key), ['radio', 'charts', 'new', 'upload']);
assert.ok(cards.every((card) => card.app === 'empyrian'));
assert.deepEqual(cards[1].items.map((item) => item.id), byPlays.slice(0, 10).map(String));
assert.ok(cards[0].items[0].stream.startsWith('https://example.test/'));
console.log('ok   Hub Cards: same sections, H-2 shape');

const listener = session();
await listener.request('/?empyrian_hub_test_login=hubtester');
let html = await listener.page();
let mine = sections(html);
assert.deepEqual(keys(mine), ['radio', 'charts', 'new', 'for-you', 'upload']);
assert.deepEqual(section(mine, 'for-you').ids, byPlays.slice(10, 18));
console.log('ok   new listener: For you shows releases just below the Charts');

const post = (body, nonce) => listener.request('/wp-json/empyrian/v1/hub/history', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', ...(nonce ? { 'X-WP-Nonce': nonce } : {}) },
  body: JSON.stringify(body),
});
const nonce = nonceOf(html);
const goofyTrack = fixtures.tracksBy.Goofy[0];
assert.equal((await post({ id: goofyTrack, position: 83, event: 'play' })).status, 401, 'no nonce, no write');
assert.equal((await post({ id: 999999, position: 0, event: 'play' }, nonce)).status, 404);
assert.equal((await post({ id: goofyTrack, position: 'x', event: 'play' }, nonce)).status, 400);
const visitorPost = await visitor.request('/wp-json/empyrian/v1/hub/history', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ id: goofyTrack, position: 1, event: 'play' }),
});
assert.equal(visitorPost.status, 401);
console.log('ok   history refuses visitors, missing nonces, unknown tracks and bad input');

assert.equal((await post({ id: goofyTrack, position: 83, event: 'play' }, nonce)).status, 204);
html = await listener.page();
mine = sections(html);
assert.deepEqual(keys(mine), ['continue', 'radio', 'charts', 'new', 'for-you', 'upload']);
assert.deepEqual(section(mine, 'continue').ids, [goofyTrack]);
assert.match(section(mine, 'continue').html, new RegExp(`data-play-id="${goofyTrack}" data-hub-resume="83" aria-label="Resume Track `));
assert.match(section(mine, 'continue').html, /Resume at 1:23/);
assert.deepEqual(section(mine, 'for-you').ids, releasesBy(['Goofy']));
console.log('ok   after one play: Continue listening resumes at 1:23; For you follows the artist');

const deelowTrack = fixtures.tracksBy.Deelow[0];
assert.equal((await post({ id: deelowTrack, liked: true, event: 'like' }, nonce)).status, 204);
mine = sections(await listener.page());
assert.deepEqual(section(mine, 'for-you').ids, releasesBy(['Goofy', 'Deelow']).slice(0, 8));
assert.deepEqual(section(mine, 'continue').ids, [goofyTrack], 'a like is not a play');
console.log('ok   a like adds the artist to For you');

const personal = await listener.request('/wp-json/empyrian/v1/hub/cards', { headers: { 'X-WP-Nonce': nonce } });
assert.match(personal.headers.get('cache-control'), /no-store.*private|private.*no-store/);
const [continueCard] = await personal.json();
assert.equal(continueCard.key, 'continue');
assert.equal(continueCard.items[0].id, String(goofyTrack));
assert.ok(continueCard.items[0].at);
console.log('ok   signed-in Hub Cards start with Continue listening');

const admin = session();
await admin.request('/?empyrian_hub_test_login=hubadmin');
const dashboard = await admin.request('/wp-admin/');
assert.equal(dashboard.status, 200);
const dashboardHtml = await dashboard.text();
assert.ok(!/critical error/i.test(dashboardHtml), 'wp-admin loads');
const restNonce = (await (await admin.request('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
const top = byPlays[0];
const setStatus = (status) => admin.request(`/wp-json/wp/v2/posts/${top}`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
  body: JSON.stringify({ status }),
});
assert.equal((await setStatus('draft')).status, 200);
assert.deepEqual(section(sections(await visitor.page()), 'charts').ids, byPlays.slice(1, 11));
assert.equal((await setStatus('publish')).status, 200);
assert.deepEqual(section(sections(await visitor.page()), 'charts').ids, byPlays.slice(0, 10));
console.log('ok   wp-admin loads; saving a post refreshes the Charts at once');

assert.equal((await fetch(`${base}/wp-cron.php?doing_wp_cron`)).status, 200);
const { debugLog } = await (await fetch(`${base}/wp-json/empyrian-hub-fixtures/v1/expected`)).json();
assert.ok(!/empyrian-home-hub|Fatal/.test(debugLog), `PHP log:\n${debugLog}`);
console.log('ok   cron runs; nothing from the hub and no fatal errors in the PHP log');
