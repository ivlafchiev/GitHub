#!/usr/bin/env bash
# Creates a throwaway WordPress site on SQLite for the Flexo Booking tests.
#
#   tests/make-site.sh <site-dir> <url> <plugin-source-dir>
#
# Needs: WP_CORE_DIR (a WordPress core checkout), WPCLI (path to wp-cli.phar)
# and either DB_NAME (+ DB_USER, DB_PASSWORD, DB_HOST) for MySQL/MariaDB, or
# SQLITE_PLUGIN_DIR (the sqlite-database-integration plugin) for SQLite.
set -euo pipefail

SITE="$1"; URL="$2"; PLUGIN_SRC="$3"
: "${WP_CORE_DIR:?}" "${WPCLI:?}"

rm -rf "$SITE"
cp -r "$WP_CORE_DIR" "$SITE"
rm -rf "$SITE/.git"
mkdir -p "$SITE/wp-content/plugins" "$SITE/wp-content/mu-plugins"
cp "$SITE/wp-config-sample.php" "$SITE/wp-config.php"
if [ -n "${DB_NAME:-}" ]; then
	mysql_cmd="mariadb -u${DB_USER:-wp} -p${DB_PASSWORD:-wp} -h${DB_HOST:-localhost}"
	$mysql_cmd -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\`;"
	sed -i "s/database_name_here/$DB_NAME/; s/username_here/${DB_USER:-wp}/; s/password_here/${DB_PASSWORD:-wp}/; s/'localhost'/'${DB_HOST:-localhost}'/" "$SITE/wp-config.php"
else
	: "${SQLITE_PLUGIN_DIR:?}"
	cp -rL "$SQLITE_PLUGIN_DIR" "$SITE/wp-content/plugins/sqlite-database-integration"
	sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$SITE/wp-content/plugins/sqlite-database-integration/db.copy" > "$SITE/wp-content/db.php"
fi
sed -i "s/define( 'WP_DEBUG', false );/define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );/" "$SITE/wp-config.php"

# Capture outgoing email instead of sending it.
cat > "$SITE/wp-content/mu-plugins/capture-mail.php" <<'PHP'
<?php
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$GLOBALS['flexo_mails'][] = $atts;
	$log = WP_CONTENT_DIR . '/mail.log';
	file_put_contents( $log, wp_json_encode( $atts ) . "\n", FILE_APPEND );
	return true;
}, 10, 2 );
PHP

WP="php $WPCLI --allow-root --path=$SITE"
$WP core install --url="$URL" --title="Flexo Test" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email >/dev/null
$WP option update siteurl "$URL" >/dev/null
$WP option update home "$URL" >/dev/null
$WP option update timezone_string "Europe/Sofia" >/dev/null
$WP rewrite structure '/%postname%/' >/dev/null
cp -r "$PLUGIN_SRC" "$SITE/wp-content/plugins/flexo-booking"
$WP plugin activate flexo-booking >/dev/null
echo "Site ready: $SITE ($URL)"
