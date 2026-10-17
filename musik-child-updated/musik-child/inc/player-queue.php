<?php
// Play Block replays the queue saved in the visitor's browser, with the stream links as they were when queued.
// Before it starts, saved entries are matched against the current catalogue so a stale one can never error on load.

add_action( 'wp_head', empyrian_safe( 'empyrian_heal_saved_queue' ), 1 );

function empyrian_heal_saved_queue() {
	$streams = get_option( 'empyrian_playable_streams', array() );
	if ( ! is_array( $streams ) || ! $streams ) {
		return;
	}
	?>
<script>
(function (streams) {
	try {
		var saved = JSON.parse(localStorage.getItem('plyr') || 'null');
		if (!saved || !Array.isArray(saved.items)) return;
		var current = saved.items[saved.active || 0];
		var items = saved.items.filter(function (item) { return item && streams[item.id]; });
		items.forEach(function (item) { item.stream_url = streams[item.id]; });
		if (!items.length) { localStorage.removeItem('plyr'); return; }
		saved.items = items;
		saved.active = Math.max(0, items.indexOf(current));
		localStorage.setItem('plyr', JSON.stringify(saved));
	} catch (error) {
		localStorage.removeItem('plyr');
	}
})(<?php echo wp_json_encode( (object) $streams ); ?>);
</script>
	<?php
}
