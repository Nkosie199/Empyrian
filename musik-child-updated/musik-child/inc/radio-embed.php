<?php
// The station's player at /?empyrian_radio=embed, built to sit inside Mynger and other sites we own.

const EMPYRIAN_RADIO_EMBED_ORIGINS = array(
	'https://mynger.com',
	'https://www.mynger.com',
	'http://localhost:3000',
	'http://localhost:4173',
);

// Mynger, plus this site's own pages that frame the player.
function empyrian_radio_embed_origins() {
	$home = wp_parse_url( home_url() );
	$self = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	return array_merge( EMPYRIAN_RADIO_EMBED_ORIGINS, array( $self ) );
}

add_action(
	'template_redirect',
	empyrian_safe(
		function () {
			if ( 'embed' !== ( $_GET['empyrian_radio'] ?? '' ) ) {
				return;
			}
			header_remove( 'X-Frame-Options' );
			header( "Content-Security-Policy: frame-ancestors 'self' " . implode( ' ', EMPYRIAN_RADIO_EMBED_ORIGINS ) );
			header( 'Cache-Control: no-store' );
			empyrian_radio_render_embed( 'dark' === ( $_GET['theme'] ?? '' ) );
			exit;
		}
	)
);

function empyrian_radio_render_embed( $dark ) {
	$config = array(
		'now'      => rest_url( 'empyrian/v1/radio/now' ),
		'guide'    => rest_url( 'empyrian/v1/radio/guide?hours=3' ),
		'origins'  => empyrian_radio_embed_origins(),
		'station'  => EMPYRIAN_RADIO_NAME,
	);
	?>
<!doctype html>
<html lang="en" data-theme="<?php echo $dark ? 'dark' : 'light'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( EMPYRIAN_RADIO_NAME ); ?></title>
<style>
:root { --bg: #ffffff; --fg: #1f1b3a; --muted: #5b5778; --accent: #605ed7; --accent-fg: #ffffff; --chip: #f59e0b; --line: #e7e5f5; --pad: 1rem; }
[data-theme="dark"] { --bg: #14122b; --fg: #f4f3ff; --muted: #b9b6d9; --accent: #a9a7f0; --accent-fg: #14122b; --line: #2c2950; }
* { box-sizing: border-box; }
html, body { margin: 0; background: var(--bg); color: var(--fg); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
/* Once the framing page sizes this to fit, a scrollbar would only fight that sizing. */
html.sized-by-parent { overflow: hidden; }
.radio { display: flex; flex-wrap: wrap; gap: var(--pad); padding: var(--pad); align-items: center; }
.art { flex: 1 1 8rem; max-width: 14rem; aspect-ratio: 1; border-radius: 0.75rem; background: var(--line) center/cover no-repeat; }
.info { flex: 3 1 14rem; display: flex; flex-direction: column; gap: 0.4rem; min-width: 0; }
.station { display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; color: var(--muted); }
.live { background: #ec4899; color: #fff; border-radius: 999px; padding: 0.1rem 0.5rem; font-weight: 700; font-size: 0.7rem; letter-spacing: 0.04em; }
.title { font-size: 1.35rem; font-weight: 800; margin: 0; overflow-wrap: anywhere; }
.artist { color: var(--muted); margin: 0; }
.controls { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; margin-top: 0.4rem; }
button { font: inherit; border: 0; border-radius: 999px; padding: 0.7em 1.3em; cursor: pointer; font-weight: 700; }
.play { background: var(--accent); color: var(--accent-fg); }
.skip { background: var(--line); color: var(--fg); }
button:focus-visible, input:focus-visible, a:focus-visible { outline: 3px solid var(--chip); outline-offset: 2px; }
label.volume { display: flex; gap: 0.4rem; align-items: center; color: var(--muted); font-size: 0.85rem; }
.next { color: var(--muted); font-size: 0.9rem; margin: 0.2rem 0 0; }
.next strong { color: var(--fg); }
a { color: var(--accent); }
.status { color: var(--muted); font-size: 0.85rem; min-height: 1.2em; margin: 0; }
</style>
</head>
<body>
<main class="radio" aria-live="polite">
	<div class="art" role="img" aria-label=""></div>
	<div class="info">
		<div class="station"><span class="live">LIVE</span><span><?php echo esc_html( EMPYRIAN_RADIO_NAME ); ?></span></div>
		<p class="title">Tuning in…</p>
		<p class="artist"></p>
		<div class="controls">
			<button type="button" class="play" aria-pressed="false">Play</button>
			<button type="button" class="skip">Skip</button>
			<label class="volume">Volume <input type="range" min="0" max="1" step="0.05" value="1"></label>
		</div>
		<p class="next"></p>
		<p class="status"></p>
		<a class="open" href="#" target="_blank" rel="noopener">Open on Empyrian</a>
	</div>
</main>
<audio preload="none"></audio>
<script>
(function () {
	var config = <?php echo wp_json_encode( $config ); ?>;
	var audio = document.querySelector('audio');
	var el = function (selector) { return document.querySelector(selector); };
	var queue = [];
	var current = null;
	var parentOrigin = null;
	var live = true;

	function post(type, payload) {
		if (window.parent === window || !parentOrigin) return;
		window.parent.postMessage(Object.assign({ type: 'mynger:station:' + type }, payload || {}), parentOrigin);
	}

	function show(track) {
		el('.title').textContent = track ? track.title : config.station + ' is off air';
		el('.artist').textContent = track ? track.artist : '';
		el('.art').style.backgroundImage = track && track.artworkUrl ? 'url("' + track.artworkUrl + '")' : '';
		el('.art').setAttribute('aria-label', track ? 'Artwork for ' + track.title : '');
		el('.open').href = track ? track.uri : '#';
		var upcoming = queue[0];
		el('.next').innerHTML = '';
		if (upcoming) {
			el('.next').append('Next up: ');
			var name = document.createElement('strong');
			name.textContent = upcoming.title;
			el('.next').append(name, ' – ' + upcoming.artist);
		}
		if (track && 'mediaSession' in navigator) {
			navigator.mediaSession.metadata = new MediaMetadata({ title: track.title, artist: track.artist, album: config.station, artwork: track.artworkUrl ? [{ src: track.artworkUrl }] : [] });
		}
		post('nowPlaying', { track: track });
	}

	function load(track, offset) {
		current = track;
		show(track);
		if (!track) return;
		audio.src = track.streamUrl;
		audio.dataset.offset = String(offset || 0);
		audio.dataset.loadedAt = String(Date.now());
	}

	function tuneIn() {
		return Promise.all([fetch(config.now).then(function (r) { return r.json(); }), fetch(config.guide).then(function (r) { return r.json(); })])
			.then(function (results) {
				var now = results[0];
				if (now.offAir || !now.now) { queue = []; load(null); post('error', { code: 'offAir', message: config.station + ' is off air' }); return; }
				queue = (results[1].slots || []).filter(function (slot) { return slot.start > now.now.start; });
				live = true;
				load(now.now, now.offsetSeconds);
			})
			.catch(function () { el('.status').textContent = 'Can’t reach the station right now.'; post('error', { code: 'network', message: 'Station unreachable' }); });
	}

	function advance() {
		if (!queue.length) { tuneIn().then(play); return; }
		live = false;
		load(queue.shift(), 0);
		play();
	}

	function play() {
		if (!current) return;
		audio.play().catch(function () { el('.status').textContent = 'Press Play to listen.'; post('error', { code: 'blocked', message: 'Playback needs a tap' }); });
	}

	function pause() { audio.pause(); }

	function state() {
		var playing = !audio.paused;
		el('.play').textContent = playing ? 'Pause' : 'Play';
		el('.play').setAttribute('aria-pressed', String(playing));
		post('state', { playing: playing, offsetSeconds: Math.round(audio.currentTime || 0), volume: audio.volume, live: live });
	}

	audio.addEventListener('loadedmetadata', function () {
		var offset = Number(audio.dataset.offset || 0);
		if (offset > 0) offset += (Date.now() - Number(audio.dataset.loadedAt)) / 1000;
		if (offset > 0 && offset < audio.duration) audio.currentTime = offset;
		audio.dataset.offset = '0';
	});
	audio.addEventListener('play', function () { el('.status').textContent = ''; state(); });
	audio.addEventListener('pause', state);
	audio.addEventListener('volumechange', state);
	audio.addEventListener('ended', advance);
	audio.addEventListener('error', function () { if (current) advance(); });

	el('.play').addEventListener('click', function () { audio.paused ? (live && current ? tuneIn().then(play) : play()) : pause(); });
	el('.skip').addEventListener('click', advance);
	el('input[type=range]').addEventListener('input', function (e) { audio.volume = Number(e.target.value); });

	if ('mediaSession' in navigator) {
		navigator.mediaSession.setActionHandler('play', play);
		navigator.mediaSession.setActionHandler('pause', pause);
		navigator.mediaSession.setActionHandler('nexttrack', advance);
	}

	// Never sent as 0: while the host page is still laying this frame out it can briefly measure 0.
	function reportSize() {
		var height = document.body.scrollHeight;
		if (height) post('size', { height: height });
	}
	new ResizeObserver(reportSize).observe(document.body);

	window.addEventListener('message', function (event) {
		if (config.origins.indexOf(event.origin) === -1 || !event.data || typeof event.data.type !== 'string') return;
		parentOrigin = event.origin;
		switch (event.data.type) {
			case 'mynger:station:hello': document.documentElement.classList.add('sized-by-parent'); post('ready', { station: config.station, version: 1 }); show(current); state(); requestAnimationFrame(reportSize); break;
			case 'mynger:station:play': audio.paused && live ? tuneIn().then(play) : play(); break;
			case 'mynger:station:pause': pause(); break;
			case 'mynger:station:next': advance(); break;
			case 'mynger:station:volume': audio.volume = Math.max(0, Math.min(1, Number(event.data.volume))); el('input[type=range]').value = String(audio.volume); break;
		}
	});

	tuneIn();
})();
</script>
</body>
</html>
	<?php
}
