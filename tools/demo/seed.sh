#!/usr/bin/env bash
# Demo site for trying the plugin and taking screenshots (all data invented).
#
#   cd tools/demo
#   ../../bin/build-zip.sh zip        # ZIP of the current code into tools/demo/zip/
#   docker compose up -d
#   ./seed.sh                         # WordPress + Polylang + this plugin + sample content
#   ./seed.sh lang pl                 # admin UI in Polish (en = back to English)
#   docker compose down -v            # remove everything
#
# Admin: http://127.0.0.1:8099/wp-admin, login admin / demo-pass-123 (local only).
set -euo pipefail
cd "$(dirname "$0")"
wp() { docker compose run --rm -T cli wp "$@" 2>/dev/null; }

if [ "${1:-}" = lang ]; then
	locale=$([ "${2:-en}" = pl ] && echo pl_PL || echo en_US)
	wp user meta update admin locale "$locale" >/dev/null
	echo "Admin language: $locale"
	exit 0
fi

echo "Waiting for the database..."
until docker compose exec -T db mariadb -uwp -pwp -e 'select 1' wp >/dev/null 2>&1; do sleep 2; done

wp core is-installed || wp core install --url=http://127.0.0.1:8099 --title="Demo Studio" \
	--admin_user=admin --admin_password=demo-pass-123 --admin_email=admin@example.com --skip-email
wp rewrite structure '/%postname%/' --hard >/dev/null
wp language core install pl_PL >/dev/null || true
wp plugin install polylang --activate >/dev/null
wp plugin delete akismet hello >/dev/null || true
wp user meta update admin show_welcome_panel 0 >/dev/null
wp eval 'PLL_Admin_Notices::dismiss( "wizard" );' >/dev/null || true
zip=$(ls zip/colorbox-wp-mcp-*.zip | tail -1)
wp plugin install "/zip/$(basename "$zip")" --force --activate >/dev/null

wp eval '
$m = PLL()->model;
if ( ! $m->get_languages_list() ) {
	$m->languages->add( array( "name" => "English", "slug" => "en", "locale" => "en_US", "rtl" => false, "term_group" => 0 ) );
	$m->languages->add( array( "name" => "Polski", "slug" => "pl", "locale" => "pl_PL", "rtl" => false, "term_group" => 1 ) );
	$m->clean_languages_cache();
}
if ( get_page_by_path( "laser-cut-lamp", OBJECT, "post" ) ) { echo "Content already there\n"; return; }
$en = wp_insert_post( array( "post_title" => "Laser-cut desk lamp", "post_name" => "laser-cut-lamp", "post_status" => "publish",
	"post_content" => "<!-- wp:paragraph --><p>Plywood, an LED strip and an evening of sanding.</p><!-- /wp:paragraph -->" ) );
$pl = wp_insert_post( array( "post_title" => "Lampka cięta laserem", "post_name" => "lampka-laser", "post_status" => "publish",
	"post_content" => "<!-- wp:paragraph --><p>Sklejka, taśma LED i wieczór szlifowania.</p><!-- /wp:paragraph -->" ) );
pll_set_post_language( $en, "en" ); pll_set_post_language( $pl, "pl" );
pll_save_post_translations( array( "en" => $en, "pl" => $pl ) );
$c = array(
	array( "Anna", "Lovely build! What plywood thickness did you use?", 1 ),
	array( "cheap-pills-4u", "Great post!! visit http://spam.example http://spam.example/2 http://spam.example/3", 0 ),
	array( "Tom", "The link to the wiring diagram is broken.", 1 ),
);
foreach ( $c as $x ) {
	wp_insert_comment( array( "comment_post_ID" => $en, "comment_author" => $x[0], "comment_author_email" => strtolower( preg_replace( "/\W/", "", $x[0] ) ) . "@example.com",
		"comment_content" => $x[1], "comment_approved" => $x[2], "comment_author_IP" => "203.0.113." . wp_rand( 1, 200 ) ) );
}
echo "Content created\n";
'
echo "Done: http://127.0.0.1:8099/wp-admin (admin / demo-pass-123)"
