<?php

/**
 * Plugin Name: Events Manager Bookings Importer
 * Plugin URI: https://github.com/andyplak/events-manager-bookings-importer
 * Description: Simple plugin to import mulitple bookings for an event via csv file.
 * Version: 0.1
 * Author: Andy Place
 * Author URI: http://www.andyplace.co.uk/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

function embi_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=event',
		'Import Bookings',
		'Import Bookings',
		'manage_options',
		'events-bookings-import',
		'embi_form'
	);
}
add_action( 'admin_menu', 'embi_admin_menu', 70 );

function embi_form() {
	global $EM_Person;

	$notices = [];
	$errors  = [];

	// Process Form
	if( isset( $_REQUEST['submit'] ) ) {

		check_admin_referer( 'import-bookings-'.get_current_user_id(), 'embi' );

		// check there are no errors
		if($_FILES['csv']['error'] == 0) {
			$name = $_FILES['csv']['name'];
			$ext  = strtolower( end( explode( '.', $_FILES['csv']['name'] ) ) );
			$type = $_FILES['csv']['type'];

			// check the file is a csv
			if( $ext === 'csv' && $type === 'text/csv' ) {
				$tmp_name = $_FILES['csv']['tmp_name'];
				$bookings_data = array_map( 'str_getcsv', file( $tmp_name ) );

				$csv_headers = array_shift( $bookings_data );

				#_dump($csv_headers);
				#_dump($bookings_data);

				foreach( $bookings_data as $booking_row ) {

					// check imported flag, continue if set


					// The following is hard coded to a particular format CSV and ticket config.
					// Customise for your own needs...
					$event_id   = 1;
					$first_name = trim( $booking_row[0] );
					$last_name  = trim( $booking_row[1] );
					$email      = trim( $booking_row[2] );
					$org        = trim( $booking_row[4] );
					$notes      = trim( $booking_row[12] );

					// Tickets

					/*
					CSV | Ticket | EM Ticket ID
					====|========|=============
					5   | Adult  | 1
					6   | Kids   | 2
					7   | Van    | 3
					8   | Car    | ?
					9   | Tent   | 4
					10  | Dogs   | ?
					*/

					// Configure your's to match live
					$em_tickets = [];

					if( $booking_row[5] > 0 ) {
						$em_tickets[1]['spaces'] = $booking_row[5];
					}

					if( $booking_row[6] > 0 ) {
						$em_tickets[2]['spaces'] = $booking_row[6];
					}

					if( $booking_row[7] > 0 ) {
						$em_tickets[3]['spaces'] = $booking_row[7];
					}

					if( $booking_row[8] > 0 ) {
						#$em_tickets[4]['spaces'] = $booking_row[8];
					}

					if( $booking_row[9] > 0 ) {
						#$em_tickets[5]['spaces'] = $booking_row[9];
					}

					if( $booking_row[10] > 0 ) {
						#$em_tickets[6]['spaces'] = $booking_row[10];
					}

					$user = get_user_by('email', $email );

					$payload = [
						'action'                  => 'booking_add',
						'em_ajax'                 => true,
						'event_id'                => $event_id,
						'_wpnonce'                => wp_create_nonce('booking_add'),
						'booking_comment'         => $notes,
						'lft_agreed'              => '1',
						'cip_agreed'              => '1',
						'data_privacy_consent'    => '1',
						'manual_booking'          => wp_create_nonce('em_manual_booking_'.$event_id),
						'gateway'                 => 'offline',
						'manual_booking_override' => '1'
					];

					$payload['em_tickets'] = $em_tickets;

					if( $user ) {
						EM_Bookings::$force_registration = false;
						$payload['person_id']    = $user->ID;
					}else{
						EM_Bookings::$force_registration = true;
						$payload['person_id']    = 0;
						$payload['user_name']    = $first_name . ' ' . $last_name;
						$payload['user_email']   = $email;
						$payload['dbem_address'] = '29 Corn Street';
						$payload['dbem_city']    = 'Bristol';
						$payload['dbem_state']   = 'Bristol';
						$payload['dbem_zip']     = 'BS1 1HT';
						$payload['dbem_country'] = 'GB';
					}

					#_dump( $payload );


					//ADD/EDIT Booking

					$EM_Event   = new EM_Event( $event_id );
					$EM_Booking = new EM_Booking();
					$EM_Person  = new EM_Person( $payload['person_id'] );

					if( get_option('dbem_bookings_double') || !$EM_Event->get_bookings()->has_booking( $user->ID ) ) {

						// Mock POST request with payload data
						$_REQUEST = $payload;
						$EM_Booking->get_post();

						$post_validation = $EM_Booking->validate();

						if( $post_validation ) {

							//register the user - or not depending - according to the booking
							$registration = em_booking_add_registration($EM_Booking);

							$EM_Bookings = $EM_Event->get_bookings();
							if( $registration && $EM_Bookings->add($EM_Booking) ){
								#if( is_user_logged_in() && is_multisite() && !is_user_member_of_blog( $user->ID, get_current_blog_id()) ){
								#	add_user_to_blog(get_current_blog_id(), $user->ID, get_option('default_role'));
								#}
								$notices[] = $EM_Bookings->feedback_message . ' ('.$email.')';
							}else{
								if(!$registration){
									$errors[] = implode( ' ', $EM_Booking->get_errors() ) .' ('.$email.')';
								}else{
									$errors[] = implode( ' ', $EM_Bookings->get_errors() ) .' ('.$email.')';
								}
							}
							global $em_temp_user_data; $em_temp_user_data = false; //delete registered user temp info (if exists)
						}else{
							$errors[] = $EM_Booking->get_errors() . ' ('.$email.')';
						}
					}else{
						$errors[] = get_option('dbem_booking_feedback_already_booked'). ' ('.$email.')';
					}
				}

			}else{
				// filetype warning
				$errors[] = __('Only csv files can be imported', 'embi');
			}
		} else {
			// handle error
			$errors[] = sprintf( __('Error with file upload: %s', 'embi'), $_FILES['csv']['error'] );
		}
	}

	?>
	<div class="wrap">
		<h1><?php _e('Import Bookings', 'embi' ) ?></h1>
		<p><?php _e('Import mulitple bookings from a formatted CSV file', 'embi' ) ?><p>

		<?php if( !empty($notices) ) : ?>
		<div class="notice notice-success">
			<?php foreach( $notices as $notice ) : ?>
				<p><?php echo $notice ?></p>
			<?php endforeach ?>
		</div>
		<?php endif; ?>

		<?php if( !empty($errors) ) : ?>
		<div class="notice notice-error">
			<?php foreach( $errors as $error ) : ?>
				<p><?php echo $error ?></p>
			<?php endforeach ?>
		</div>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'import-bookings-'.get_current_user_id(), 'embi' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th>
							<label for="csv"><?php _e('CSV file with bookings') ?></label>
						</th>
						<td>
							<input type="file" name="csv" value="" />
						</td>
					</tr>
					<tr>
						<th></th>
						<td>
							<input type="submit" name="submit" class="button button-primary" value="<?php _e('Import') ?>" />
						</td>
					</tr>
				</tbody>
			</table>
		</form>
	</div>
	<?php
}