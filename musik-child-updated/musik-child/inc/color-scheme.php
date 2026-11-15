<?php
// The site follows the visitor's light or dark setting; Muzik already styles html.dark.
// It runs first in <head> so the page never flashes the wrong scheme.

add_action( 'wp_head', empyrian_safe( 'empyrian_follow_color_scheme' ), 0 );

function empyrian_follow_color_scheme() {
	?>
<script>
(function () {
	if (!window.matchMedia) return;
	var root = document.documentElement;
	var dark = window.matchMedia('(prefers-color-scheme: dark)');
	var apply = function () {
		root.classList.toggle('dark', dark.matches);
		root.classList.toggle('light', !dark.matches);
	};
	apply();
	dark.addEventListener('change', apply);
})();
</script>
	<?php
}
