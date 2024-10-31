<?php
/*
Plugin Name: Post Star Rating
Plugin URI: http://blog.abusemagazine.com/index.php/category/post-star-rating/
Description: Plugin that allows to rate a post with one to five stars
Author: O Doutor, BestWebLayout
Version: 0.3.5
Author URI: http://blog.abusemagazine.com/index.php/author/o-doutor/
License: GPLv3 or later
*/

/* Copyright 2005 O Doutor (email : doutorquen@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

/*
* Create tables in the database.
*/
if ( ! function_exists ( 'psr_create_table' ) ) {
	function psr_create_table() {
		global $wpdb;
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		$psr_table_post = $wpdb->prefix . 'psr_post';
		$psr_table_user = $wpdb->prefix . 'psr_user';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$psr_table_post}'" ) !== $psr_table_post ) {
			$sql = "CREATE TABLE {$psr_table_post} (
				ID bigint(20) unsigned NOT NULL default '0',
				votes int(10) unsigned NOT NULL default '0',
				points int(10) unsigned NOT NULL default '0',
				PRIMARY KEY (ID));";
			dbDelta( $sql );
		}
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$psr_table_user}'" ) !== $psr_table_user ) {
			$sql = "CREATE TABLE {$psr_table_user} (
				user varchar(32) NOT NULL default '',
				post bigint(20) unsigned NOT NULL default '0',
				points int(10) unsigned NOT NULL default '0',
				ip char(15) NOT NULL,
				vote_date datetime NOT NULL,
				PRIMARY KEY (`user`,post),
				KEY vote_date (vote_date));";
			dbDelta( $sql );
		}
	}
}

/*
* Add localization to the plugin and create user for voting.
*/
if ( ! function_exists( 'psr_init' ) ) {
	function psr_init() {
		load_plugin_textdomain( 'psr', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		if ( ! isset( $_COOKIE['wp_psr'] ) ) {
			$psr_user = md5( microtime() . rand( 1000, 90000000 ) );
			setcookie( 'wp_psr', $psr_user, time() + 60 * 60 * 24 * 365, '/' );
		}
	}
}

/*
* Add script and styles to the front-end.
*/
if ( ! function_exists( 'psr_frontend_head' ) ) {
	function psr_frontend_head() {
		wp_enqueue_style( 'psr_style', plugins_url( 'css/style.css', __FILE__ ) );
		wp_enqueue_script( 'psr_script', plugins_url( 'js/script.js', __FILE__ ), array( 'jquery' ) );
		wp_localize_script( 'psr_script', 'psr_ajax',
			array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( plugin_basename( __FILE__ ), 'psr_ajax_nonce' )
			)
		);
	}
}

/*
* Get the html that shows the stars for voting. If the user has already vote then it shows stars with puntuation. No voting is allowed.
*/
if ( ! function_exists( 'psr_show_voting_stars' ) ) {
	function psr_show_voting_stars( $psr_post_id = false, $psr_points = false ) {
		global $wpdb, $post;
		$psr_rated = false;
		$psr_user = isset( $_COOKIE['wp_psr'] ) ? esc_sql( $_COOKIE['wp_psr'] ) : false;
		$psr_table_user = $wpdb->prefix . 'psr_user';
		$psr_table_post = $wpdb->prefix . 'psr_post';
		if ( ! $psr_post_id ) {
			$psr_post_id = $post->ID;
		}
		if ( $psr_user ) {
			$psr_rated = (bool) $wpdb->get_var(
				"SELECT COUNT(*)
				FROM {$psr_table_user}
				WHERE user='{$psr_user}'
				AND post={$psr_post_id}"
			);
		}
		if ( $psr_user && $psr_points > 0 && ! $psr_rated ) {
			$psr_ip = $_SERVER['REMOTE_ADDR'];
			$psr_vote_date = date( 'Y-m-d H:i:s' );
			$wpdb->query(
				"INSERT INTO {$psr_table_user} (user, post, points, ip, vote_date)
				VALUES ('{$psr_user}', {$psr_post_id}, {$psr_points}, '{$psr_ip}', '{$psr_vote_date}')"
			);
			if ( $wpdb->get_var( "SELECT COUNT(*) FROM {$psr_table_post} WHERE ID={$psr_post_id}" ) ) {
				$wpdb->query(
					"UPDATE {$psr_table_post}
					SET votes=votes+1, points=points+{$psr_points}
					WHERE ID={$psr_post_id};"
				);
			} else {
				$wpdb->query(
					"INSERT INTO {$psr_table_post} (ID, votes, points)
					VALUES ({$psr_post_id}, 1, {$psr_points});"
				);
			}
			$psr_rated = true;
		}
		$psr_data = $wpdb->get_row( "SELECT votes, points FROM {$psr_table_post} WHERE ID={$psr_post_id}" );
		$psr_data_votes = isset( $psr_data->votes ) ? (int) $psr_data->votes : 0;
		$psr_data_points = isset( $psr_data->points ) ? (int) $psr_data->points : 0;
		if ( $psr_rated || ! $psr_user ) {
			$psr_html = psr_draw_stars( $psr_data_votes, $psr_data_points );
		} else {
			$psr_html = psr_draw_voting_stars( $psr_data_votes, $psr_data_points, $psr_post_id );
		}
		echo $psr_html;
	}
}

/*
* Draw the stars.
*/
if ( ! function_exists( 'psr_draw_stars' ) ) {
	function psr_draw_stars( $psr_votes, $psr_points ) {
		if ( $psr_votes > 0 ) {
			$psr_rate = $psr_points / $psr_votes;
		} else {
			$psr_rate = 0;
		}
		$psr_html = '<div class="PSR_container"><div class="PSR_stars">';
		for ( $i = 1; $i <= 5; ++$i ) {
			if ( $i <= $psr_rate ) {
				$psr_class = 'PSR_full_star';
				$psr_char = '*';
			} elseif ( $i <= ( $psr_rate + .5 ) ) {
				$psr_class = 'PSR_half_star';
				$psr_char = '&frac12;';
			} else {
				$psr_class = 'PSR_no_star';
				$psr_char = '&nbsp;';
			}
			$psr_html .= sprintf( '<span class="%s">%s</span>', $psr_class, $psr_char );
		}
		$psr_html .= sprintf( '<span class="PSR_votes">%d</span><span class="PSR_tvotes">%s</span>', $psr_votes, _n( 'vote', 'votes', $psr_votes, 'psr' ) );
		$psr_html .= '</div></div>';
		return $psr_html;
	}
}

/*
* Draw the voting stars.
*/
if ( ! function_exists( 'psr_draw_voting_stars' ) ) {
	function psr_draw_voting_stars( $psr_votes, $psr_points, $psr_post_id ) {
		if ( $psr_votes > 0 ) {
			$psr_rate = $psr_points / $psr_votes;
		} else {
			$psr_rate = 0;
		}
		$psr_html = sprintf( '<div class="PSR_container"><form id="PSR_form_%1$d" action="#PSR_form_%1$d" method="post" class="PSR_stars">', $psr_post_id );
		for ( $i = 1; $i <= 5; ++$i ) {
			if ( $i <= $psr_rate ) {
				$psr_class = 'PSR_full_voting_star';
				$psr_char = '*';
			} elseif ( $i <= ( $psr_rate + .5 ) ) {
				$psr_class = 'PSR_half_voting_star';
				$psr_char = '&frac12;';
			} else {
				$psr_class = 'PSR_no_voting_star';
				$psr_char = '&nbsp;';
			}
			$psr_html .= sprintf(
				'<input type="radio" id="psr_star_%1$d_%2$d" class="psr_star" name="psr_stars" value="%2$d" /><label class="%3$s" for="psr_star_%1$d_%2$d">%2$d</label> ',
				$psr_post_id,
				$i,
				$psr_class
			);
		}
		if ( $psr_votes > 0 ) {
			$psr_html .= sprintf( '<span class="PSR_votes">%d</span><span class="PSR_tvotes">%s</span>', $psr_votes, _n( 'vote', 'votes', $psr_votes, 'psr' ) );
		} else {
			$psr_html .= sprintf( '<span class="PSR_tvote">%s</span>', __( 'Vote!', 'psr' ) );
		}
		$psr_html .= sprintf( '<input type="hidden" name="p" value="%d" />', $psr_post_id );
		$psr_html .= sprintf( '<input type="submit" name="vote" value="%s" />', __( 'Vote', 'psr' ) );
		$psr_html .= '</form></div>';
		return $psr_html;
	}
}

/*
* Save vote post to database.
*/
if ( ! function_exists( 'psr_save_vote' ) ) {
	function psr_save_vote() {
		check_ajax_referer( plugin_basename( __FILE__ ), 'psr_ajax_nonce' );
		$psr_post_id = isset( $_POST['psr_post_id'] ) ? $_POST['psr_post_id'] : false;
		$psr_points = ( isset( $_POST['psr_points'] ) && $_POST['psr_points'] > 0 && $_POST['psr_points'] <= 5 ) ? (int) $_POST['psr_points'] : false;
		if ( $psr_post_id && $psr_points ) {
			psr_show_voting_stars( $psr_post_id, $psr_points );
		}
		exit;
	}
}

/*
* Draw the best post of the month.
*/
if ( ! function_exists( 'psr_bests_of_month' ) ) {
	function psr_bests_of_month( $psr_month = null, $psr_limit = 10 ) {
		global $wpdb;
		$psr_month = is_null( $psr_month ) ? date( 'm' ) : (int) $psr_month;
		$psr_limit = (int) $psr_limit;
		$psr_table_user = $wpdb->prefix . 'psr_user';
		$psr_data = $wpdb->get_results(
			"SELECT post, COUNT(*) AS votes, SUM(points) AS points, AVG(points)
			FROM {$psr_table_user}
			WHERE MONTH(vote_date)={$psr_month} AND YEAR(vote_date)=YEAR(NOW())
			GROUP BY 1
			ORDER BY 4 DESC, 2 DESC
			LIMIT {$psr_limit}"
		);
		if ( is_array( $psr_data ) ) {
			$psr_html = '<ol class="PSR_month_scores">';
			foreach ( $psr_data AS $psr_row ) {
				$psr_permalink = get_permalink( $psr_row->post );
				$psr_title = get_the_title( $psr_row->post );
				$psr_html .= sprintf( '<li><a class="psr_post_title" href="%1$s" title="%2$s">%2$s</a>%3$s</li>', $psr_permalink, $psr_title, psr_draw_stars( $psr_row->votes, $psr_row->points ) );
			}
			$psr_html .= '</ol>';
			return $psr_html;
		}
	}
}

/*
* Shortcode for display the best post of the month.
*/
if ( ! function_exists( 'psr_get_bests_of_month' ) ) {
	function psr_get_bests_of_month( $atts ) {
		$psr_month = isset( $atts['month'] ) ? $atts['month'] : null;
		$psr_limit = isset( $atts['limit'] ) ? $atts['limit'] : 10;
		return psr_bests_of_month( $psr_month, $psr_limit );
	}
}

/*
* Draw the best post of the moment. The moment is the time between now and 30 days before.
*/
if ( ! function_exists( 'psr_bests_of_moment' ) ) {
	function psr_bests_of_moment( $psr_limit = 10 ) {
		global $wpdb;
		$psr_limit = (int) $psr_limit;
		$psr_table_user = $wpdb->prefix . 'psr_user';
		$psr_avg = (int) $wpdb->get_var(
			"SELECT COUNT(*) / COUNT( DISTINCT post ) AS votes
			FROM {$psr_table_user}
			WHERE vote_date BETWEEN DATE_SUB(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 1 MONTH)
			AND DATE_SUB(NOW(), INTERVAL 1 DAY)"
		);
		$psr_data = $wpdb->get_results(
			"SELECT post, COUNT(*) AS votes, SUM(points) AS points, AVG(points)
			FROM {$psr_table_user}
			WHERE vote_date BETWEEN DATE_SUB(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 1 MONTH)
			AND DATE_SUB(NOW(), INTERVAL 1 DAY)
			GROUP BY 1
			HAVING votes > {$psr_avg}
			ORDER BY 4 DESC, 2 DESC
			LIMIT {$psr_limit}"
		);
		$psr_old_score = array();
		if ( is_array( $psr_data ) ) {
			$i = 1;
			foreach ( $psr_data AS $psr_row ) {
				$psr_old_score[ $psr_row->post ] = $i++;
			}
		}
		$psr_avg = (int) $wpdb->get_var(
			"SELECT COUNT(*) / COUNT( DISTINCT post ) AS votes
			FROM {$psr_table_user}
			WHERE vote_date BETWEEN DATE_SUB(NOW(), INTERVAL 1 MONTH)
			AND NOW()"
		);
		$psr_score = $wpdb->get_results(
			"SELECT post, COUNT(*) AS votes, SUM(points) AS points, AVG(points)
			FROM {$psr_table_user}
			WHERE vote_date BETWEEN DATE_SUB(NOW(), INTERVAL 1 MONTH)
			AND NOW()
			GROUP BY 1
			HAVING votes > {$psr_avg}
			ORDER BY 4 DESC, 2 DESC
			LIMIT {$psr_limit}"
		);
		if ( is_array( $psr_score ) ) {
			$psr_html = '<ol class="PSR_moment_scores">';
			$psr_position = 1;
			$psr_trends = array( __( 'Down', 'psr' ), __( 'Up', 'psr' ), __( 'Unchanged', 'psr' ) );
			foreach ( $psr_score AS $psr_row ) {
				$psr_permalink = get_permalink( $psr_row->post );
				$psr_title = get_the_title( $psr_row->post );
				$psr_html .= '<li>';
				if ( is_array( $psr_old_score ) ) {
					$psr_trend = sprintf( '<span class="trend trend_up" title="%s"><img src="%s"></span>', $psr_trends[1], plugins_url( 'images/up_arrow.png', __FILE__ ) );
					if ( isset( $psr_old_score[ $psr_row->post ] ) ) {
						if ( $psr_position > $psr_old_score[ $psr_row->post ] ) {
							$psr_trend = sprintf( '<span class="trend trend_dw" title="%s"><img src="%s"></span>', $psr_trends[0], plugins_url( 'images/dw_arrow.png', __FILE__ ) );
						} elseif ($psr_position == $psr_old_score[ $psr_row->post ] ) {
							$psr_trend = sprintf( '<span class="trend trend_eq" title="%s"><img src="%s"></span>', $psr_trends[2], plugins_url( 'images/eq_arrow.png', __FILE__ ) );
						}
					}
					$psr_html .= $psr_trend;
				}
				$psr_html .= sprintf( '<a class="post_title" href="%s" title="%s">%s</a>', $psr_permalink, $psr_title, $psr_title );
				$psr_html .= psr_draw_stars( $psr_row->votes, $psr_row->points );
				$psr_html .= '</li>';
				$psr_position++;
			}
			$psr_html .= '</ol>';
			return $psr_html;
		}
	}
}

/*
* Shortcode for display the best post of the moment.
*/
if ( ! function_exists( 'psr_get_bests_of_moment' ) ) {
	function psr_get_bests_of_moment( $atts ) {
		$psr_limit = isset( $atts['limit'] ) ? $atts['limit'] : 10;
			return psr_bests_of_moment( $psr_limit );
	}
}

/* Create DB tables */
register_activation_hook( __FILE__, 'psr_create_table' );
/* Initialization */
add_action( 'init', 'psr_init' );
/* Adding scripts and styles to the frontend */
add_action( 'wp_enqueue_scripts', 'psr_frontend_head' );
/* Adding a plugin support shortcode */
add_shortcode( 'psr_bests_of_month', 'psr_get_bests_of_month' );
add_shortcode( 'psr_bests_of_moment', 'psr_get_bests_of_moment' );
/* Adding ajax support for voting */
add_action( 'wp_ajax_psr_save_vote', 'psr_save_vote' );
add_action( 'wp_ajax_nopriv_psr_save_vote', 'psr_save_vote' );
?>