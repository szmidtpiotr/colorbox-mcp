<?php
/**
 * Tests for the post date field (date) on the demo site. Runs the abilities exactly as the MCP server does,
 * as an Editor account, including input schema validation and permission checks.
 *
 *   cd tools/demo && ./test-date.sh
 *
 * Exit code 0 = all passed.
 */

// phpcs:disable

// wp eval-file runs this inside a method, so the counters live in $GLOBALS explicitly.
$GLOBALS['date_test'] = array( 'pass' => 0, 'fail' => 0 );
function check( string $name, bool $ok, $detail = '' ): void {
	$GLOBALS['date_test'][ $ok ? 'pass' : 'fail' ]++;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : '  → ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) ) . "\n";
}
function run( string $ability, array $input ) {
	$a = wp_get_ability( $ability );
	return $a ? $a->execute( $input ) : new WP_Error( 'missing', "no ability $ability" );
}
function err_code( $r ): string {
	return is_wp_error( $r ) ? $r->get_error_code() : '(no error)';
}
function fresh( int $id ): WP_Post {
	clean_post_cache( $id );
	return get_post( $id );
}
function log_actions( int $id ): array {
	return array_column( (array) get_post_meta( $id, '_colorbox_agent_log', true ), 'action' );
}

// A time zone with an offset, so local time and GMT really differ.
update_option( 'timezone_string', 'Europe/Warsaw' );
update_option( 'gmt_offset', '' );

$editor = get_user_by( 'login', 'test-agent' );
if ( ! $editor ) {
	$editor = get_user_by( 'id', wp_create_user( 'test-agent', wp_generate_password(), 'test-agent@example.com' ) );
	$editor->set_role( 'editor' );
}
wp_set_current_user( $editor->ID );

// 1. create-draft with a backdated date: pinned (GMT set), survives a later edit and publishing.
$r = run( 'colorbox/create-draft', array( 'title' => 'Date test: create', 'lang' => 'en', 'date' => '2020-05-01 12:00' ) );
check( 'create-draft accepts date', ! is_wp_error( $r ), err_code( $r ) );
if ( ! is_wp_error( $r ) ) {
	$p = fresh( $r['id'] );
	check( 'create-draft: post_date', '2020-05-01 12:00:00' === $p->post_date, $p->post_date );
	check( 'create-draft: post_date_gmt = local − 2 h (CEST)', '2020-05-01 10:00:00' === $p->post_date_gmt, $p->post_date_gmt );
	check( 'create-draft: date_fixed true', true === $r['date_fixed'] );
	check( 'create-draft: logged set-date', in_array( 'set-date', log_actions( $p->ID ), true ), log_actions( $p->ID ) );
}

// 2. update-post on a draft without a date (the common case: an existing draft gets backdated).
$draft = run( 'colorbox/create-draft', array( 'title' => 'Date test: update', 'lang' => 'en' ) );
$id    = (int) $draft['id'];
check( 'new draft without date: post_date_gmt is zero', '0000-00-00 00:00:00' === fresh( $id )->post_date_gmt, fresh( $id )->post_date_gmt );
check( 'new draft without date: date_fixed false', false === $draft['date_fixed'] );

$r = run( 'colorbox/update-post', array( 'id' => $id, 'date' => '2021-01-15 09:30:15' ) );
check( 'update-post accepts date', ! is_wp_error( $r ), err_code( $r ) );
$p = fresh( $id );
check( 'update-post: post_date', '2021-01-15 09:30:15' === $p->post_date, $p->post_date );
check( 'update-post: post_date_gmt non-zero and = local − 1 h (CET)', '2021-01-15 08:30:15' === $p->post_date_gmt, $p->post_date_gmt );
check( 'update-post: still a draft', 'draft' === $p->post_status, $p->post_status );
$log = (array) get_post_meta( $id, '_colorbox_agent_log', true );
$last = end( $log );
check( 'update-post: log set-date with empty date_from', 'set-date' === ( $last['action'] ?? '' ) && '' === $last['date_from'] && '2021-01-15 09:30:15' === $last['date_to'], $last );

$r2 = run( 'colorbox/get-post', array( 'id' => $id ) );
check( 'get-post re-read: same date', '2021-01-15 09:30:15' === $r2['date'], $r2['date'] );
check( 'get-post re-read: date_fixed true', true === $r2['date_fixed'] );

run( 'colorbox/update-post', array( 'id' => $id, 'title' => 'Date test: update (edited)' ) );
check( 'edit without date keeps the date', '2021-01-15 09:30:15' === fresh( $id )->post_date, fresh( $id )->post_date );
$count = count( log_actions( $id ) );
run( 'colorbox/update-post', array( 'id' => $id, 'date' => '2021-01-15 09:30:15' ) );
check( 'same date again is not logged twice', count( log_actions( $id ) ) === $count, log_actions( $id ) );

run( 'colorbox/set-review-status', array( 'id' => $id, 'status' => 'pending', 'note' => 'test' ) );
check( 'pending keeps the date', '2021-01-15 09:30:15' === fresh( $id )->post_date, fresh( $id )->post_date );

// The owner publishes (as admin, the agent cannot).
wp_set_current_user( 1 );
wp_publish_post( $id );
wp_set_current_user( $editor->ID );
$p = fresh( $id );
check( 'after publishing: status publish', 'publish' === $p->post_status, $p->post_status );
check( 'after publishing: date kept', '2021-01-15 09:30:15' === $p->post_date && '2021-01-15 08:30:15' === $p->post_date_gmt, array( $p->post_date, $p->post_date_gmt ) );

// 3. Refused input, draft left unchanged.
$bad    = (int) run( 'colorbox/create-draft', array( 'title' => 'Date test: refused', 'lang' => 'en', 'date' => '2022-02-02 12:00' ) )['id'];
foreach (
	array(
		'future date'          => array( gmdate( 'Y-m-d H:i', time() + 2 * DAY_IN_SECONDS ), 'colorbox_future_date' ),
		'Polish format'        => array( '15.01.2021 10:00', 'colorbox_bad_date' ),
		'date only'            => array( '2021-01-15', 'colorbox_bad_date' ),
		'ISO with T'           => array( '2021-01-15T10:00:00', 'colorbox_bad_date' ),
		'31 February'          => array( '2021-02-31 10:00', 'colorbox_bad_date' ),
		'hour 25'              => array( '2021-01-15 25:00', 'colorbox_bad_date' ),
		'DST gap (02:30 CEST)' => array( '2021-03-28 02:30', 'colorbox_bad_date' ),
	) as $label => $case
) {
	$r = run( 'colorbox/update-post', array( 'id' => $bad, 'title' => 'changed?', 'date' => $case[0] ) );
	check( "refused: $label", $case[1] === err_code( $r ), err_code( $r ) );
}
$r = run( 'colorbox/update-post', array( 'id' => $bad, 'date' => 20210115 ) );
check( 'refused: number instead of string', is_wp_error( $r ), err_code( $r ) );
$p = fresh( $bad );
check( 'refused calls changed nothing (title, date)', 'Date test: refused' === $p->post_title && '2022-02-02 12:00:00' === $p->post_date, array( $p->post_title, $p->post_date ) );
$r = run( 'colorbox/create-draft', array( 'title' => 'Date test: future', 'date' => gmdate( 'Y-m-d H:i', time() + DAY_IN_SECONDS ) ) );
check( 'create-draft refuses future date (no post created)', 'colorbox_future_date' === err_code( $r ) && ! get_posts( array( 'title' => 'Date test: future', 'post_status' => 'any', 'lang' => '' ) ), err_code( $r ) );

// 4. update-published-post on the post published in step 2: date changed, revision + previous_date for undo,
// both actions logged.
$pub = $id;
$old = fresh( $pub )->post_date;
$r   = run( 'colorbox/update-published-post', array( 'id' => $pub, 'reason' => 'chronology', 'date' => '2019-11-02 20:15' ) );
check( 'update-published-post accepts date', ! is_wp_error( $r ), err_code( $r ) );
if ( ! is_wp_error( $r ) ) {
	$p = fresh( $pub );
	check( 'update-published-post: date set, still published', '2019-11-02 20:15:00' === $p->post_date && 'publish' === $p->post_status, array( $p->post_date, $p->post_status ) );
	check( 'update-published-post: previous_date returned', $old === ( $r['previous_date'] ?? null ), $r['previous_date'] ?? null );
	$rev_id = (int) $r['undo_revision_id'];
	check( 'update-published-post: undo revision exists', $rev_id > 0 && wp_get_post_revision( $rev_id ), $rev_id );
	$log  = (array) get_post_meta( $pub, '_colorbox_agent_log', true );
	$tail = array_slice( $log, -2 );
	check( 'update-published-post: log set-date (with reason) + update-published', 'set-date' === $tail[0]['action'] && 'chronology' === $tail[0]['reason'] && $old === $tail[0]['date_from'] && 'update-published' === $tail[1]['action'], $tail );
	$u = run( 'colorbox/update-published-post', array( 'id' => $pub, 'reason' => 'undo', 'date' => $r['previous_date'] ) );
	check( 'undo via previous_date', ! is_wp_error( $u ) && $old === fresh( $pub )->post_date, fresh( $pub )->post_date );
}

// 5. A scheduled post: the agent cannot change its date.
wp_set_current_user( 1 );
$future = wp_insert_post( array( 'post_title' => 'Date test: scheduled', 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) );
wp_set_current_user( $editor->ID );
$r = run( 'colorbox/update-published-post', array( 'id' => $future, 'reason' => 'x', 'date' => '2020-01-01 10:00' ) );
check( 'scheduled post: date change refused', 'colorbox_scheduled_date' === err_code( $r ) && 'future' === fresh( $future )->post_status, err_code( $r ) );

// Clean up the test posts (as admin).
wp_set_current_user( 1 );
foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 's' => 'Date test', 'lang' => '' ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}

echo "\n{$GLOBALS['date_test']['pass']} passed, {$GLOBALS['date_test']['fail']} failed\n";
exit( $GLOBALS['date_test']['fail'] ? 1 : 0 );
