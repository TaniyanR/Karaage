<?php
/**
 * Plugin Name: Karaage
 * Plugin URI:  https://github.com/TaniyanR/Karaage
 * Description: 過去の公開記事をランダムに選び、一定期間の重複を避けながら専用RSSとして配信します。
 * Version:     1.1.1
 * Author:      TaniyanR
 * License:     GPL-2.0-or-later
 * Text Domain: karaage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KARAAGE_VERSION', '1.1.1' );
define( 'KARAAGE_OPTION_INTERVAL', 'karaage_update_interval' );
define( 'KARAAGE_OPTION_COOLDOWN', 'karaage_repeat_prevention_days' );
define( 'KARAAGE_OPTION_CURRENT', 'karaage_current_feed_post_ids' );
define( 'KARAAGE_OPTION_HISTORY', 'karaage_post_history' );
define( 'KARAAGE_OPTION_BUILT_AT', 'karaage_feed_built_at' );
define( 'KARAAGE_OPTION_MIN_AGE', 'karaage_minimum_post_age_days' );
define( 'KARAAGE_CRON_HOOK', 'karaage_refresh_feed_event' );

function karaage_interval_choices() {
	return array(
		'10min'  => array( 'label' => '10分', 'seconds' => 10 * MINUTE_IN_SECONDS ),
		'30min'  => array( 'label' => '30分', 'seconds' => 30 * MINUTE_IN_SECONDS ),
		'60min'  => array( 'label' => '60分', 'seconds' => HOUR_IN_SECONDS ),
		'3hours' => array( 'label' => '3時間', 'seconds' => 3 * HOUR_IN_SECONDS ),
		'6hours' => array( 'label' => '6時間', 'seconds' => 6 * HOUR_IN_SECONDS ),
	);
}

function karaage_cooldown_choices() {
	return array(
		1   => '1日',
		7   => '7日',
		30  => '30日',
		60  => '60日',
		180 => '180日',
	);
}

function karaage_min_age_choices() {
	return array(
		0   => '制限なし',
		1   => '1日以上前',
		7   => '7日以上前',
		30  => '30日以上前',
		60  => '60日以上前',
		90  => '90日以上前',
		180 => '180日以上前',
		365 => '365日以上前',
	);
}

function karaage_add_cron_schedules( $schedules ) {
	foreach ( karaage_interval_choices() as $key => $choice ) {
		$schedules[ 'karaage_' . $key ] = array(
			'interval' => $choice['seconds'],
			'display'  => 'Karaage ' . $choice['label'],
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'karaage_add_cron_schedules' );

function karaage_register_feed() {
	add_feed( 'karaage', 'karaage_render_feed' );
}
add_action( 'init', 'karaage_register_feed' );

function karaage_activate() {
	if ( false === get_option( KARAAGE_OPTION_INTERVAL, false ) ) {
		add_option( KARAAGE_OPTION_INTERVAL, '60min' );
	}
	if ( false === get_option( KARAAGE_OPTION_COOLDOWN, false ) ) {
		add_option( KARAAGE_OPTION_COOLDOWN, 7 );
	}
	if ( false === get_option( KARAAGE_OPTION_MIN_AGE, false ) ) {
		add_option( KARAAGE_OPTION_MIN_AGE, 0 );
	}

	delete_option( 'karaage_category_ids' );

	karaage_register_feed();
	flush_rewrite_rules();
	karaage_reschedule_event();
	karaage_generate_feed();
}
register_activation_hook( __FILE__, 'karaage_activate' );

function karaage_deactivate() {
	wp_clear_scheduled_hook( KARAAGE_CRON_HOOK );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'karaage_deactivate' );

function karaage_reschedule_event() {
	wp_clear_scheduled_hook( KARAAGE_CRON_HOOK );
	$interval = get_option( KARAAGE_OPTION_INTERVAL, '60min' );
	$choices  = karaage_interval_choices();
	if ( ! isset( $choices[ $interval ] ) ) {
		$interval = '60min';
	}
	wp_schedule_event( time() + MINUTE_IN_SECONDS, 'karaage_' . $interval, KARAAGE_CRON_HOOK );
}

function karaage_cron_refresh_feed() {
	karaage_generate_feed();
}
add_action( KARAAGE_CRON_HOOK, 'karaage_cron_refresh_feed' );

function karaage_interval_updated( $old_value, $value ) {
	if ( $old_value !== $value ) {
		karaage_reschedule_event();
	}
}
add_action( 'update_option_' . KARAAGE_OPTION_INTERVAL, 'karaage_interval_updated', 10, 2 );

function karaage_feed_filter_updated( $old_value, $value ) {
	if ( $old_value !== $value ) {
		karaage_generate_feed();
	}
}
add_action( 'update_option_' . KARAAGE_OPTION_COOLDOWN, 'karaage_feed_filter_updated', 10, 2 );
add_action( 'update_option_' . KARAAGE_OPTION_MIN_AGE, 'karaage_feed_filter_updated', 10, 2 );

function karaage_prune_history( $history ) {
	if ( ! is_array( $history ) ) {
		return array();
	}
	$oldest_allowed = time() - ( 180 * DAY_IN_SECONDS );
	foreach ( $history as $post_id => $used_at ) {
		if ( ! is_numeric( $used_at ) || (int) $used_at < $oldest_allowed ) {
			unset( $history[ $post_id ] );
		}
	}
	return $history;
}

function karaage_generate_feed() {
	$cooldown_days = (int) get_option( KARAAGE_OPTION_COOLDOWN, 7 );
	$choices       = karaage_cooldown_choices();
	if ( ! isset( $choices[ $cooldown_days ] ) ) {
		$cooldown_days = 7;
	}

	$history = karaage_prune_history( get_option( KARAAGE_OPTION_HISTORY, array() ) );
	$cutoff  = time() - ( $cooldown_days * DAY_IN_SECONDS );
	$exclude = array();
	foreach ( $history as $post_id => $used_at ) {
		if ( (int) $used_at >= $cutoff ) {
			$exclude[] = (int) $post_id;
		}
	}

	$count           = max( 1, (int) get_option( 'posts_per_rss', 10 ) );
	$min_age         = (int) get_option( KARAAGE_OPTION_MIN_AGE, 0 );
	$min_age_choices = karaage_min_age_choices();
	if ( ! isset( $min_age_choices[ $min_age ] ) ) {
		$min_age = 0;
	}

	$query_args = array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => $count,
		'orderby'                => 'rand',
		'post__not_in'           => $exclude,
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'fields'                 => 'ids',
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	if ( $min_age > 0 ) {
		$query_args['date_query'] = array(
			array(
				'before'    => gmdate( 'Y-m-d H:i:s', time() - ( $min_age * DAY_IN_SECONDS ) ),
				'inclusive' => true,
				'column'    => 'post_date_gmt',
			),
		);
	}

	$query    = new WP_Query( $query_args );
	$post_ids = array_map( 'intval', $query->posts );
	$now      = time();
	foreach ( $post_ids as $post_id ) {
		$history[ $post_id ] = $now;
	}

	update_option( KARAAGE_OPTION_CURRENT, $post_ids, false );
	update_option( KARAAGE_OPTION_HISTORY, $history, false );
	update_option( KARAAGE_OPTION_BUILT_AT, $now, false );
	return $post_ids;
}

function karaage_render_feed() {
	$post_ids = get_option( KARAAGE_OPTION_CURRENT, array() );
	if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
		$post_ids = karaage_generate_feed();
	}

	$posts = array();
	if ( ! empty( $post_ids ) ) {
		$posts = get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'post__in'            => array_map( 'intval', $post_ids ),
				'orderby'             => 'post__in',
				'posts_per_page'      => count( $post_ids ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'suppress_filters'    => false,
			)
		);
	}

	$built_at = (int) get_option( KARAAGE_OPTION_BUILT_AT, time() );
	status_header( 200 );
	header( 'Content-Type: ' . feed_content_type( 'rss-http' ) . '; charset=' . get_option( 'blog_charset' ), true );
	echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?' . '>';
	?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
	<title><?php echo esc_html( get_bloginfo_rss( 'name' ) . ' - Karaage' ); ?></title>
	<atom:link href="<?php echo esc_url( get_feed_link( 'karaage' ) ); ?>" rel="self" type="application/rss+xml" />
	<link><?php echo esc_url( home_url( '/' ) ); ?></link>
	<description><?php echo esc_html( get_bloginfo_rss( 'description' ) ); ?></description>
	<lastBuildDate><?php echo esc_html( gmdate( 'D, d M Y H:i:s +0000', $built_at ) ); ?></lastBuildDate>
	<language><?php echo esc_html( get_bloginfo_rss( 'language' ) ); ?></language>
<?php foreach ( $posts as $post ) : setup_postdata( $post ); ?>
	<item>
		<title><?php the_title_rss(); ?></title>
		<link><?php the_permalink_rss(); ?></link>
		<guid isPermaLink="false"><?php the_guid(); ?></guid>
		<pubDate><?php echo esc_html( get_post_time( 'D, d M Y H:i:s +0000', true, $post ) ); ?></pubDate>
		<dc:creator><![CDATA[<?php echo esc_html( get_the_author() ); ?>]]></dc:creator>
		<description><![CDATA[<?php the_excerpt_rss(); ?>]]></description>
		<content:encoded><![CDATA[<?php echo get_the_content_feed( 'rss2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>]]></content:encoded>
	</item>
<?php endforeach; wp_reset_postdata(); ?>
</channel>
</rss>
	<?php
	exit;
}

function karaage_admin_menu() {
	add_options_page( 'Karaage', 'Karaage', 'manage_options', 'karaage', 'karaage_settings_page' );
}
add_action( 'admin_menu', 'karaage_admin_menu' );

function karaage_register_settings() {
	register_setting( 'karaage_settings', KARAAGE_OPTION_COOLDOWN, array( 'type' => 'integer', 'sanitize_callback' => 'karaage_sanitize_cooldown', 'default' => 7 ) );
	register_setting( 'karaage_settings', KARAAGE_OPTION_INTERVAL, array( 'type' => 'string', 'sanitize_callback' => 'karaage_sanitize_interval', 'default' => '60min' ) );
	register_setting( 'karaage_settings', KARAAGE_OPTION_MIN_AGE, array( 'type' => 'integer', 'sanitize_callback' => 'karaage_sanitize_min_age', 'default' => 0 ) );
}
add_action( 'admin_init', 'karaage_register_settings' );

function karaage_sanitize_cooldown( $value ) {
	$value = (int) $value;
	$choices = karaage_cooldown_choices();
	return isset( $choices[ $value ] ) ? $value : 7;
}

function karaage_sanitize_interval( $value ) {
	$value = sanitize_key( $value );
	$choices = karaage_interval_choices();
	return isset( $choices[ $value ] ) ? $value : '60min';
}

function karaage_sanitize_min_age( $value ) {
	$value = (int) $value;
	$choices = karaage_min_age_choices();
	return isset( $choices[ $value ] ) ? $value : 0;
}

function karaage_handle_manual_refresh() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '権限がありません。', 'karaage' ) );
	}
	check_admin_referer( 'karaage_manual_refresh' );
	karaage_generate_feed();
	wp_safe_redirect( add_query_arg( array( 'page' => 'karaage', 'karaage_refreshed' => '1' ), admin_url( 'options-general.php' ) ) );
	exit;
}
add_action( 'admin_post_karaage_manual_refresh', 'karaage_handle_manual_refresh' );

function karaage_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$cooldown = (int) get_option( KARAAGE_OPTION_COOLDOWN, 7 );
	$interval = get_option( KARAAGE_OPTION_INTERVAL, '60min' );
	$min_age  = (int) get_option( KARAAGE_OPTION_MIN_AGE, 0 );
	$built_at = (int) get_option( KARAAGE_OPTION_BUILT_AT, 0 );
	?>
	<div class="wrap">
		<h1>Karaage</h1>
		<?php if ( isset( $_GET['karaage_refreshed'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['karaage_refreshed'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p>RSSを更新しました。</p></div>
		<?php endif; ?>
		<p>過去の公開記事をランダムに選び、一定期間は同じ記事を再利用しない専用RSSです。</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'karaage_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( KARAAGE_OPTION_COOLDOWN ); ?>">同じ記事を再利用しない期間</label></th>
					<td><select id="<?php echo esc_attr( KARAAGE_OPTION_COOLDOWN ); ?>" name="<?php echo esc_attr( KARAAGE_OPTION_COOLDOWN ); ?>"><?php foreach ( karaage_cooldown_choices() as $days => $label ) : ?><option value="<?php echo esc_attr( $days ); ?>" <?php selected( $cooldown, $days ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( KARAAGE_OPTION_MIN_AGE ); ?>">対象記事の古さ</label></th>
					<td><select id="<?php echo esc_attr( KARAAGE_OPTION_MIN_AGE ); ?>" name="<?php echo esc_attr( KARAAGE_OPTION_MIN_AGE ); ?>"><?php foreach ( karaage_min_age_choices() as $days => $label ) : ?><option value="<?php echo esc_attr( $days ); ?>" <?php selected( $min_age, $days ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description">例：「30日以上前」なら、公開から30日以上経過した記事だけをRSS候補にします。</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( KARAAGE_OPTION_INTERVAL ); ?>">RSS更新間隔</label></th>
					<td><select id="<?php echo esc_attr( KARAAGE_OPTION_INTERVAL ); ?>" name="<?php echo esc_attr( KARAAGE_OPTION_INTERVAL ); ?>"><?php foreach ( karaage_interval_choices() as $key => $choice ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $interval, $key ); ?>><?php echo esc_html( $choice['label'] ); ?></option><?php endforeach; ?></select><p class="description">WP-Cronで更新します。アクセスがないサイトでは実行時刻が遅れる場合があります。</p></td>
				</tr>
			</table>
			<?php submit_button( '設定を保存' ); ?>
		</form>
		<hr>
		<h2>RSS情報</h2>
		<table class="widefat striped" style="max-width:900px;"><tbody>
			<tr><td style="width:220px;"><strong>RSS URL</strong></td><td><code><?php echo esc_html( get_feed_link( 'karaage' ) ); ?></code></td></tr>
			<tr><td><strong>RSSの記事数</strong></td><td><?php echo esc_html( max( 1, (int) get_option( 'posts_per_rss', 10 ) ) ); ?>件（WordPress「設定 → 表示設定 → RSS/Atom フィードで表示する最新の投稿数」を使用）</td></tr>
			<tr><td><strong>最終生成</strong></td><td><?php echo $built_at ? esc_html( wp_date( 'Y-m-d H:i:s', $built_at ) ) : '未生成'; ?></td></tr>
		</tbody></table>
		<p><a class="button button-secondary" href="<?php echo esc_url( get_feed_link( 'karaage' ) ); ?>" target="_blank" rel="noopener noreferrer">RSSを開く</a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="karaage_manual_refresh">
			<?php wp_nonce_field( 'karaage_manual_refresh' ); ?>
			<?php submit_button( '今すぐRSSを更新', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
