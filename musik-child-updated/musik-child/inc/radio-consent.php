<?php
// Creators opt in to the station; nothing of theirs airs without it.

const EMPYRIAN_RADIO_CONSENT_META = 'empyrian_radio_opt_in';

function empyrian_radio_has_consent( $user_id ) {
	return '1' === get_user_meta( (int) $user_id, EMPYRIAN_RADIO_CONSENT_META, true );
}

function empyrian_radio_render_consent_field( WP_User $user ) {
	?>
	<h2><?php echo esc_html( EMPYRIAN_RADIO_NAME ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Airplay', 'musik-child' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( EMPYRIAN_RADIO_CONSENT_META ); ?>" value="1" <?php checked( empyrian_radio_has_consent( $user->ID ) ); ?>>
					<?php echo esc_html( sprintf( __( 'Play my public tracks on %s', 'musik-child' ), EMPYRIAN_RADIO_NAME ) ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Off by default. Private tracks never air.', 'musik-child' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

function empyrian_radio_save_consent( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$opted_in = ! empty( $_POST[ EMPYRIAN_RADIO_CONSENT_META ] );
	if ( $opted_in !== empyrian_radio_has_consent( $user_id ) ) {
		update_user_meta( $user_id, EMPYRIAN_RADIO_CONSENT_META, $opted_in ? '1' : '0' );
		wp_schedule_single_event( time(), 'empyrian_radio_refresh_soon' );
	}
}

add_action( 'show_user_profile', empyrian_safe( 'empyrian_radio_render_consent_field' ) );
add_action( 'edit_user_profile', empyrian_safe( 'empyrian_radio_render_consent_field' ) );
add_action( 'personal_options_update', empyrian_safe( 'empyrian_radio_save_consent' ) );
add_action( 'edit_user_profile_update', empyrian_safe( 'empyrian_radio_save_consent' ) );
