<?php
/*
Plugin Name: Benutzeraktivität
Plugin URI: https://n3rds.work
Description: Sammelt Benutzeraktivitätsdaten und stellt sie über eine Registerkarte unter dem Site-Administrator zur Verfügung
Author: WWMS N@W
Version: 1.2
Network: true
Text Domain: user_activity
Author URI: https://n3rds.work
*/

/*
Copyright 2018-2021 WMS N@W (https://n3rds.work)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/
require 'psource/psource-plugin-update/plugin-update-checker.php';
$MyUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://n3rds.work//wp-update-server/?action=get_metadata&slug=user-activity', 
	__FILE__, 
	'user-activity' 
);
/**
 * Plugin main class
 */
class User_Activity {

	/**
	 * Current version of the plugin
	 */
	private $current_version = '1.2';

	private $page_id;

	/**
	 * Constructor
	 */
	function __construct() {

		add_action( 'admin_init', array( $this, 'init' ) );

		if ( is_multisite() ) {
			add_action( 'admin_menu', array( $this, 'pre_3_1_network_admin_page' ) );
			add_action( 'network_admin_menu', array( $this, 'network_admin_page' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'admin_page' ) );
		}

		add_action( 'admin_footer', array( $this, 'global_db_sync' ) );
		add_action( 'wp_footer', array( $this, 'global_db_sync' ) );

		add_action( 'ua_remove_old_activity', array( $this, 'remove_old_activity' ) );

		if ( did_action( 'plugins_loaded' ) ) {
			self::plugin_textdomain();
		} else {
			add_action( 'plugins_loaded', array( __CLASS__, 'plugin_textdomain' ) );
		}

	}

	/**
	 * Initializer
	 */
	function init() {

		$current_version = get_site_option( 'user_activity_version' );
		if ( ! $current_version || version_compare( $current_version, $this->current_version ) == -1 ) {
			update_site_option( 'user_activity_version', $this->current_version );
			$this->install();
		}

		// Do we have to remove old user activity?
		$transient = get_site_transient( 'user_activity_remove_old_activity' );
		if ( ! $transient ) {
			$this->remove_old_activity();
			set_site_transient( 'user_activity_remove_old_activity', true, 86400 );
		}
	}


	private function remove_old_activity() {
		global $wpdb;

		$last_31_days = time() - 2678400;

		$pq = $wpdb->prepare("DELETE FROM {$wpdb->base_prefix}user_activity_log WHERE visit_date < %d", $last_31_days);
		$wpdb->query( $pq );
	}

	public static function plugin_textdomain() {
		load_plugin_textdomain( 'user_activity', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
	}

	/**
	 * Create plugin tables
	 */
	function install() {
		global $wpdb;

		if ( @is_file( ABSPATH . '/wp-admin/includes/upgrade.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/upgrade.php';
		} else {
			die( __( "Unable to find 'wp-admin/upgrade-functions.php' and 'wp-admin/includes/upgrade.php'", 'user_activity' ) );
		}

		$db_charset_collate = '';

		if ( ! empty( $wpdb->charset ) ) {
			$db_charset_collate .= "DEFAULT CHARACTER SET $wpdb->charset";
		}
		if ( ! empty( $wpdb->collate ) ) {
			$db_charset_collate .= " COLLATE $wpdb->collate";
		}

		$table = $wpdb->base_prefix . 'user_activity';
		$sql = "CREATE TABLE $table (
			active_ID bigint(20) unsigned NOT NULL auto_increment,
			user_ID bigint(35) NOT NULL default '0',
			last_active bigint(35) NOT NULL default '0',
			PRIMARY KEY  (active_ID)
		      ) ENGINE=MyISAM $db_charset_collate;";
		dbDelta( $sql );

		$table = $wpdb->base_prefix . 'user_activity_log';
		$sql = "CREATE TABLE $table (
			log_ID bigint(20) unsigned NOT NULL auto_increment,
			user_ID bigint(35) NOT NULL default '0',
			visit_date bigint(35) NOT NULL default '0',
			PRIMARY KEY  (log_ID),
			KEY visit_date (visit_date),
			KEY user_id (user_ID)
		      ) ENGINE=MyISAM $db_charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create or update current user activity entry
	 */
	function global_db_sync() {
		global $wpdb, $current_user;

		if ( ! $current_user->ID ) {
			return;
		}

		$tmp_user_activity_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}user_activity WHERE user_ID = '%d'", $current_user->ID ) );

		if ( '0' == $tmp_user_activity_count ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->base_prefix}user_activity ( user_ID, last_active ) VALUES ( '%d', '%d' )", $current_user->ID, time() ) );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->base_prefix}user_activity SET last_active = '%d' WHERE user_ID = '%d'", time(), $current_user->ID ) );
		}

		// We'll count a visit every 30 minutes, so a refreshing before 30min will not be considered a visit
		$visited = get_site_transient( 'user_activity_' . $current_user->ID );

		if ( ! $visited ) {
			$wpdb->insert(
				$wpdb->base_prefix . 'user_activity_log',
				array(
					'user_ID' => $current_user->ID,
					'visit_date' => time()
				),
				array( '%d', '%d' )
			);
			set_site_transient( 'user_activity_' . $current_user->ID, true, 1800 );
		}
	}

	/**
	 * Get activity from db for a set period of type
	 * @param string|int $tmp_period
	 */
	function get_activity( $tmp_period ) {
		global $wpdb, $current_user;

		$tmp_period = ( $tmp_period == '' || $tmp_period == 0 ) ? 1 : $tmp_period;
		$tmp_period = $tmp_period * 60;
		$user_current_stamp = time();
		$tmp_stamp = $user_current_stamp - $tmp_period;
		$tmp_output = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}user_activity WHERE last_active > '%d'", $tmp_stamp ) );

		echo $tmp_output;
	}

	private function add_admin_menu( $parent_slug, $capability ) {

		$this->page_id = add_submenu_page(
			$parent_slug,
			__( 'Benutzeraktivität', 'user_activity' ),
			__( 'Benutzeraktivität', 'user_activity' ),
			$capability,
			'user_activity_main',
			array( $this, 'page_main_output' )
		);
	}

	/**
	 * Add network admin page
	 */
	function network_admin_page() {
		$this->add_admin_menu( 'settings.php', 'manage_network_options' );
	}

	/**
	 * Add network admin page the old way
	 */
	function pre_3_1_network_admin_page() {
		$this->add_admin_menu( 'ms-admin.php', 'manage_network_options' );
	}

	/**
	 * Add admin page for singlesite
	 */
	function admin_page() {
		$this->add_admin_menu( 'users.php', 'edit_users' );
	}

	public function setup_meta_boxes() {
	    wp_enqueue_script( 'postbox' );
	}

	/**
	 * Admin page output.
	 */
	function page_main_output() {
		global $wpdb, $wp_roles, $current_user, $wp_meta_boxes;

		// Allow access for users with correct permissions only
		if ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
			die( __( 'Netter Versuch...', 'user_activity' ) );
		} elseif ( ! is_multisite() && ! current_user_can( 'manage_options' ) ) {
			die( __( 'Netter Versuch...', 'user_activity' ) );
		}

		echo '<div class="wrap">';

		//echo '<div class="postbox">';
		//do_meta_boxes( 'user_activity_main', 'advanced', 'dfdff' );
		//echo '</div>';
		$current_stamp = time();

		$current_five_minutes = $current_stamp - 300;
		$current_hour = $current_stamp - 3600;
		$current_day = $current_stamp - 86400;
		$current_week = $current_stamp - 604800;
		$current_month = $current_stamp - 2592000;

		$five_minutes = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}user_activity WHERE last_active > '$current_five_minutes'" );
		$hour = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}user_activity WHERE last_active > '$current_hour'" );
		$day = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}user_activity WHERE last_active > '$current_day'" );
		$week = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}user_activity WHERE last_active > '$current_week'" );
		$month = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}user_activity WHERE last_active > '$current_month'" );

		$now = time();
		$last_day = $now - 86400;
		$today_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COUNT(log_ID) visits,user_ID FROM {$wpdb->base_prefix}user_activity_log WHERE visit_date > %d GROUP BY user_ID ORDER BY visits DESC LIMIT 10",
				$last_day
			)
		);

		$last_7_days = $now - 604800;
		$last_7_days_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COUNT(log_ID) visits,user_ID FROM {$wpdb->base_prefix}user_activity_log WHERE visit_date > %d GROUP BY user_ID ORDER BY visits DESC LIMIT 10",
				$last_7_days
			)
		);


		$last_month = $now - 2592000;
		$last_month_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COUNT(log_ID) visits,user_ID FROM {$wpdb->base_prefix}user_activity_log WHERE visit_date > %d GROUP BY user_ID ORDER BY visits DESC LIMIT 10",
				$last_month
			)
		);

		?>
		<h2><?php _e( 'Benutzeraktivität', 'user_activity' ); ?></h2>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-1">
				<div id="postbox-container-1" class="postbox-container">
					<div id="advanced-sortables" class="meta-box-sortables ui-sortable">
						<div id="ua-totals-box" class="postbox" style="display: block;">
							<div class="handlediv" title="Klicken um zu ändern"><br></div>
							<h3 class="hndle"><span><?php _e( 'Gesamt', 'user_activity' ); ?></span></h3>
							<div class="inside">
								<table class="form-table">
									<thead>
									<th><h4><?php _e( 'Aktive Benutzer innerhalb von', 'user_activity' ); ?></h4></th>
									<th><h4 class="ua-visits"><?php _e( 'Einzigartige Besuche', 'user_activity' ); ?></h4></th>
									</thead>
									<tbody>
									<tr>
										<td><?php _e( 'Fünf Minuten', 'user_activity' ); ?></td>
										<td class="ua-visits"><?php echo $five_minutes; ?></td>
									</tr>
									<tr>
										<td><?php _e( 'Stunde', 'user_activity' ); ?></td>
										<td class="ua-visits"><?php echo $hour; ?></td>
									</tr>
									<tr>
										<td><?php _e( 'Tag', 'user_activity' ); ?></td>
										<td class="ua-visits"><?php echo $day; ?></td>
									</tr>
									<tr>
										<td><?php _e( 'Woche', 'user_activity' ); ?></td>
										<td class="ua-visits"><?php echo $week; ?></td>
									</tr>
									<tr>
										<td><?php _e( '30 Tage', 'user_activity' ); ?></td>
										<td class="ua-visits"><?php echo $month; ?></td>
									</tr>
									</tbody>
								</table>
							</div>
						</div>

						<div id="ua-today-box" class="postbox" style="display: block;">
							<div class="handlediv" title="Klicken um zu ändern"><br></div>
							<h3 class="hndle"><span><?php _e( 'Heute', 'user_activity' ); ?></span></h3>
							<div class="inside">
								<table class="form-table">
									<thead>
									<th><h4><?php _e( 'Benutzer', 'user_activity' ); ?></h4></th>
									<th><h4 class="ua-visits"><?php _e( 'Besuche', 'user_activity' ); ?></h4></th>
									</thead>
									<tbody>
									<?php foreach ( $today_results as $row ): ?>
										<?php
										$user = get_userdata( $row->user_ID );
										$nicename = isset( $user->data->user_nicename ) ? $user->data->user_nicename : __( 'Unbekannt', 'user_activity' );
										$user_link = $user ? '<a href="' . network_admin_url( 'user-edit.php?user_id=' . $row->user_ID ) . '">' . $nicename . '</a>' : $nicename;
										?>
										<tr>
											<td><?php echo $user_link; ?></td>
											<td class="ua-visits"><?php echo $row->visits; ?></td>
										</tr>
									<?php endforeach; ?>
									</tbody>

								</table>
							</div>
						</div>

						<div id="ua-seven-days-box" class="postbox" style="display: block;">
							<div class="handlediv" title="Klicken um zu ändern"><br></div>
							<h3 class="hndle"><span><?php _e( 'Letzten 7 Tage', 'user_activity' ); ?></span></h3>
							<div class="inside">
								<table class="form-table">
									<thead>
									<th><h4><?php _e( 'Benutzer', 'user_activity' ); ?></h4></th>
									<th><h4 class="ua-visits"><?php _e( 'Besuche', 'user_activity' ); ?></h4></th>
									</thead>
									<tbody>
									<?php foreach ( $last_7_days_results as $row ): ?>
										<?php
										$user = get_userdata( $row->user_ID );
										$nicename = isset( $user->data->user_nicename ) ? $user->data->user_nicename : __( 'Unbekannt', 'user_activity' );
										$user_link = $user ? '<a href="' . network_admin_url( 'user-edit.php?user_id=' . $row->user_ID ) . '">' . $nicename . '</a>' : $nicename;
										?>
										<tr>
											<td><?php echo $user_link; ?></td>
											<td class="ua-visits"><?php echo $row->visits; ?></td>
										</tr>
									<?php endforeach; ?>
									</tbody>

								</table>
							</div>
						</div>

						<div id="ua-30-days-box" class="postbox" style="display: block;">
							<div class="handlediv" title="Klicken um zu ändern"><br></div>
							<h3 class="hndle"><span><?php _e( 'Letzte 30 Tage', 'user_activity' ); ?></span></h3>
							<div class="inside">
								<table class="form-table">
									<thead>
									<th><h4><?php _e( 'Benutzer', 'user_activity' ); ?></h4></th>
									<th><h4 class="ua-visits"><?php _e( 'Besuche', 'user_activity' ); ?></h4></th>
									</thead>
									<tbody>
									<?php foreach ( $last_month_results as $row ): ?>
										<?php
										$user = get_userdata( $row->user_ID );
										$nicename = isset( $user->data->user_nicename ) ? $user->data->user_nicename : __( 'Unbekannt', 'user_activity' );
										$user_link = $user ? '<a href="' . network_admin_url( 'user-edit.php?user_id=' . $row->user_ID ) . '">' . $nicename . '</a>' : $nicename;
										?>
										<tr>
											<td><?php echo $user_link; ?></td>
											<td class="ua-visits"><?php echo $row->visits; ?></td>
										</tr>
									<?php endforeach; ?>
									</tbody>

								</table>
							</div>
						</div>

					</div>
				</div>
			</div>
		</div>

		<style>

		.form-table tr {
			border-bottom: 1px solid #DEDEDE;
		}

		.form-table tr:last-child {
			border-bottom: none;
		}

		.postbox {
			width: 20%;
			margin-right: 4%;
			float: left;
		}

		.form-table h4 {
			line-height: 1.7em;
			font-weight: normal;
			font-size: 13px;
			margin: 0 0 .2em;
			padding: 0;
			font-family: Georgia, "Times New Roman", "Bitstream Charter", Times, serif;
		}

		.ua-visits {
			text-align: center;
		}

		</style>
		<?php
	}

}

$user_activity = new User_Activity();

/**
 * Display number of active users for a specific period of time
 *
 * @param $tmp_period
 */
function display_user_activity( $tmp_period ) {
	global $user_activity;
	$user_activity->get_activity( $tmp_period );
}

/**
 * Display last active users
 *
 * @param int    $minutes
 * @param int    $limit
 * @param string $global_before
 * @param string $before
 * @param string $global_after
 * @param string $after
 * @param string $avatars
 * @param int    $avatar_size
 */
function user_activity_output( $minutes = 5, $limit = 10, $global_before = '', $before = '', $global_after = '', $after = '', $avatars = 'yes', $avatar_size = 32 ) {
	global $wpdb;

	$user_activity_current_stamp = time();
	$user_activity_seconds = $minutes * 60;
	$user_activity_stamp = $user_activity_current_stamp - $user_activity_seconds;
	$query = $wpdb->prepare( "SELECT * FROM {$wpdb->base_prefix}user_activity WHERE last_active > '%d' LIMIT %d", $user_activity_stamp, (int) $limit );
	$active_users = $wpdb->get_results( $query, ARRAY_A );

	if ( count( $active_users ) > 0 ) {
		echo $global_before;

		foreach ( $active_users as $active_user ) {
			echo $before;

			$user = get_user_by( 'id', $active_user['user_ID'] );
			$display_name = empty( $user->display_name ) ? $user->user_login : $user->display_name;

			$primary_blog = get_active_blog_for_user( $active_user['user_ID'] );

			if ( 'yes' == $avatars ) {
				echo get_avatar( $active_user['user_ID'], $avatar_size, get_option( 'avatar_default' ) );
				printf(
					' <a href="%s" style="text-decoration: none; border: none;">%s</a>',
					esc_url( 'http://' . $primary_blog->domain . $primary_blog->path ), esc_html( $display_name )
				);

			} else {
				printf( '<a href="%s">%s</a>', get_site_url( $primary_blog->blog_id, '/' ), $display_name );
			}

			echo $after;
		}

		echo $global_after;
	}
}


