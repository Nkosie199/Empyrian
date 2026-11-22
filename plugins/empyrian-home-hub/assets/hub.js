// Tells the hub what a signed-in listener plays (and where they stop) and likes, and resumes a track
// from Continue listening where they left off. Play Block exposes its player as window.playlist.
(function ($, config) {
	if (!$ || window.empyrianHubStarted) return;
	window.empyrianHubStarted = true;

	var SAVE_EVERY_MS = 30000;
	var RESUME_TIMEOUT_MS = 10000;

	function player() {
		var list = window.playlist;
		return list && list.player && typeof list.getCurrent === 'function' ? list : null;
	}

	function nowPlaying() {
		var list = player();
		var item = list && list.getCurrent();
		return item && item.id ? { id: Number(item.id), position: Math.floor(list.player.currentTime || 0) } : null;
	}

	function save(body) {
		if (!config || !config.nonce) return;
		fetch(config.historyUrl, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify(body)
		}).catch(function () {});
	}

	function savePosition() {
		var current = nowPlaying();
		if (current) save({ id: current.id, position: current.position, event: 'play' });
	}

	$(document).on('played', function (event, data) {
		var current = nowPlaying();
		var id = Number(data && data.id);
		if (id) save({ id: id, position: current && current.id === id ? current.position : 0, event: 'play' });
	});

	$(document).on('like.play', function (event, id, liked, type) {
		if (type === 'post' && Number(id)) save({ id: Number(id), liked: !!liked, event: 'like' });
	});

	setInterval(function () {
		var list = player();
		if (list && !list.player.paused) savePosition();
	}, SAVE_EVERY_MS);

	window.addEventListener('pagehide', savePosition);

	// Play Block starts the track from the same click; once it is loaded, jump to the saved position.
	$(document).on('click', '[data-hub-resume]', function () {
		var id = Number($(this).attr('data-play-id'));
		var position = Number($(this).attr('data-hub-resume'));
		var startedAt = Date.now();
		var timer = setInterval(function () {
			var list = player();
			var current = list && list.getCurrent();
			if (current && Number(current.id) === id && list.player.duration > position) {
				list.player.currentTime = position;
				clearInterval(timer);
			} else if (Date.now() - startedAt > RESUME_TIMEOUT_MS) {
				clearInterval(timer);
			}
		}, 250);
	});
})(window.jQuery, window.empyrianHub);
