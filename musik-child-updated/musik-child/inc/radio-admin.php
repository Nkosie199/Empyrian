<?php
// Tools → Radio guide: admins see what airs next, pin a track to a time, remove a slot or rebuild the guide.

const EMPYRIAN_RADIO_ADMIN_PAGE  = 'empyrian-radio-guide';
const EMPYRIAN_RADIO_ADMIN_HOURS = 12;

add_action(
	'admin_menu',
	empyrian_safe(
		function () {
			add_management_page( __( 'Radio guide', 'musik-child' ), __( 'Radio guide', 'musik-child' ), 'manage_options', EMPYRIAN_RADIO_ADMIN_PAGE, empyrian_safe( 'empyrian_radio_render_admin', '' ) );
		}
	)
);

foreach ( array( 'pin', 'unpin', 'remove', 'rebuild' ) as $empyrian_radio_action ) {
	add_action( 'admin_post_empyrian_radio_' . $empyrian_radio_action, empyrian_safe( 'empyrian_radio_handle_' . $empyrian_radio_action ) );
}

function empyrian_radio_admin_url( $notice = '' ) {
	return add_query_arg( array_filter( array( 'page' => EMPYRIAN_RADIO_ADMIN_PAGE, 'notice' => $notice ) ), admin_url( 'tools.php' ) );
}

function empyrian_radio_require_admin( $action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Only administrators can edit the radio guide.', 'musik-child' ), 403 );
	}
	check_admin_referer( 'empyrian_radio_' . $action );
}

function empyrian_radio_handle_pin() {
	empyrian_radio_require_admin( 'pin' );
	$id = absint( $_POST['track'] ?? 0 );
	$at = new DateTimeImmutable( sanitize_text_field( wp_unslash( $_POST['at'] ?? '' ) ), wp_timezone() );
	if ( ! isset( empyrian_radio_tracks()[ $id ] ) || $at->getTimestamp() < time() ) {
		wp_safe_redirect( empyrian_radio_admin_url( 'invalid' ) );
		exit;
	}
	$pins   = empyrian_radio_pins();
	$pins[] = array(
		'key' => wp_generate_uuid4(),
		'id'  => $id,
		'at'  => $at->getTimestamp(),
	);
	empyrian_radio_save_pins( $pins );
	empyrian_radio_replan_from( $at->getTimestamp() );
	wp_safe_redirect( empyrian_radio_admin_url( 'pinned' ) );
	exit;
}

function empyrian_radio_handle_unpin() {
	empyrian_radio_require_admin( 'unpin' );
	$key  = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
	$pins = empyrian_radio_pins();
	$pin  = current( wp_list_filter( $pins, array( 'key' => $key ) ) );
	empyrian_radio_save_pins( wp_list_filter( $pins, array( 'key' => $key ), 'NOT' ) );
	if ( $pin ) {
		empyrian_radio_replan_from( $pin['at'] );
	}
	wp_safe_redirect( empyrian_radio_admin_url( 'unpinned' ) );
	exit;
}

function empyrian_radio_handle_remove() {
	empyrian_radio_require_admin( 'remove' );
	$start = absint( $_POST['start'] ?? 0 );
	$slot  = current( wp_list_filter( empyrian_radio_schedule(), array( 'start' => $start ) ) );
	if ( $slot && $start > time() ) {
		if ( isset( $slot['pin'] ) ) {
			empyrian_radio_save_pins( wp_list_filter( empyrian_radio_pins(), array( 'key' => $slot['pin'] ), 'NOT' ) );
		}
		empyrian_radio_replan_from( $start, array( $slot['id'] ) );
	}
	wp_safe_redirect( empyrian_radio_admin_url( 'removed' ) );
	exit;
}

function empyrian_radio_handle_rebuild() {
	empyrian_radio_require_admin( 'rebuild' );
	empyrian_radio_replan_from( time() + 1 );
	wp_safe_redirect( empyrian_radio_admin_url( 'rebuilt' ) );
	exit;
}

function empyrian_radio_admin_button( $action, $label, array $fields = array(), $class = 'button' ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
		<input type="hidden" name="action" value="<?php echo esc_attr( 'empyrian_radio_' . $action ); ?>">
		<?php wp_nonce_field( 'empyrian_radio_' . $action ); ?>
		<?php foreach ( $fields as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php endforeach; ?>
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
}

function empyrian_radio_render_admin() {
	$notices = array(
		'pinned'   => __( 'Track scheduled.', 'musik-child' ),
		'unpinned' => __( 'Scheduled track cancelled.', 'musik-child' ),
		'removed'  => __( 'Slot removed and the guide replanned from there.', 'musik-child' ),
		'rebuilt'  => __( 'Guide rebuilt.', 'musik-child' ),
		'invalid'  => __( 'Choose a track and a time in the future.', 'musik-child' ),
	);
	$notice = sanitize_key( $_GET['notice'] ?? '' );
	$tracks = empyrian_radio_tracks();
	uasort(
		$tracks,
		function ( $a, $b ) {
			return strcasecmp( $a['title'], $b['title'] );
		}
	);
	$slots = empyrian_radio_slots( time(), EMPYRIAN_RADIO_ADMIN_HOURS );
	$time  = function ( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	};
	?>
	<div class="wrap">
		<h1><?php echo esc_html( EMPYRIAN_RADIO_NAME . ' — ' . __( 'guide', 'musik-child' ) ); ?></h1>
		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<div class="notice <?php echo 'invalid' === $notice ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
		<?php endif; ?>
		<p><?php echo esc_html( sprintf( __( '%d tracks in rotation. Listeners see this guide on the Radio page and in Mynger.', 'musik-child' ), count( $tracks ) ) ); ?></p>

		<h2><?php esc_html_e( 'Schedule a track', 'musik-child' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="empyrian_radio_pin">
			<?php wp_nonce_field( 'empyrian_radio_pin' ); ?>
			<label><?php esc_html_e( 'Track', 'musik-child' ); ?>
				<select name="track" required>
					<?php foreach ( $tracks as $track ) : ?>
						<option value="<?php echo esc_attr( $track['id'] ); ?>"><?php echo esc_html( $track['title'] . ( $track['artist'] ? ' — ' . $track['artist'] : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'At', 'musik-child' ); ?>
				<input type="datetime-local" name="at" required min="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>">
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Schedule', 'musik-child' ); ?></button>
			<p class="description"><?php esc_html_e( 'It airs as soon as the track playing at that time ends.', 'musik-child' ); ?></p>
		</form>

		<?php $pins = array_filter( empyrian_radio_pins(), fn( $pin ) => $pin['at'] > time() - HOUR_IN_SECONDS ); ?>
		<?php if ( $pins ) : ?>
			<h2><?php esc_html_e( 'Scheduled tracks', 'musik-child' ); ?></h2>
			<ul>
				<?php foreach ( $pins as $pin ) : ?>
					<li>
						<?php echo esc_html( $time( $pin['at'] ) . ' — ' . ( $tracks[ $pin['id'] ]['title'] ?? '#' . $pin['id'] ) ); ?>
						<?php empyrian_radio_admin_button( 'unpin', __( 'Cancel', 'musik-child' ), array( 'key' => $pin['key'] ), 'button-link' ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h2><?php echo esc_html( sprintf( __( 'Next %d hours', 'musik-child' ), EMPYRIAN_RADIO_ADMIN_HOURS ) ); ?></h2>
		<p><?php empyrian_radio_admin_button( 'rebuild', __( 'Rebuild the guide', 'musik-child' ) ); ?></p>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Starts', 'musik-child' ); ?></th><th><?php esc_html_e( 'Track', 'musik-child' ); ?></th><th><?php esc_html_e( 'Artist', 'musik-child' ); ?></th><th><?php esc_html_e( 'Length', 'musik-child' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $slots as $index => $slot ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'time_format' ), $slot['start'] ) ); ?><?php echo 0 === $index ? ' <strong>' . esc_html__( 'on air', 'musik-child' ) . '</strong>' : ''; ?></td>
						<td><?php echo esc_html( $slot['title'] ); ?><?php echo $slot['pinned'] ? ' <em>' . esc_html__( '(scheduled)', 'musik-child' ) . '</em>' : ''; ?></td>
						<td><?php echo esc_html( $slot['artist'] ); ?></td>
						<td><?php echo esc_html( gmdate( 'i:s', $slot['duration'] ) ); ?></td>
						<td>
							<?php
							if ( $index > 0 ) {
								empyrian_radio_admin_button( 'remove', __( 'Remove', 'musik-child' ), array( 'start' => $slot['start'] ), 'button-link button-link-delete' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
