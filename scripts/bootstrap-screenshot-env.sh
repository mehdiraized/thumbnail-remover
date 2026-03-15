#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_FILE="$ROOT_DIR/.wp-env.screenshots.json"
FIXTURE_DIR="$ROOT_DIR/output/screenshots/fixtures"
PLUGIN_SLUG="${PLUGIN_SLUG:-thumbnail-remover}"
PLUGIN_PATH="/var/www/html/wp-content/plugins/$PLUGIN_SLUG"

mkdir -p "$ROOT_DIR/output/screenshots"
php "$ROOT_DIR/scripts/generate-screenshot-fixtures.php" "$FIXTURE_DIR"
cp "$CONFIG_FILE" "$ROOT_DIR/.wp-env.json"

npx wp-env start --update

npx wp-env run cli --env-cwd="wp-content/plugins/$PLUGIN_SLUG" -- sh -lc "
set -e
wp plugin activate '$PLUGIN_SLUG'
wp option update blogname 'Thumbnail Remover Demo'
wp option update blogdescription 'Automated screenshot environment'

attachment_ids=\$(wp post list --post_type=attachment --format=ids)
if [ -n \"\$attachment_ids\" ]; then
  wp post delete \$attachment_ids --force
fi

imported_ids=\$(wp media import $PLUGIN_PATH/output/screenshots/fixtures/*.jpg --porcelain)
set -- \$imported_ids
first_id=\${1:-}
second_id=\${2:-}

demo_post_id=\$(wp post create --post_type=post --post_status=publish --post_title='Thumbnail Manager Demo Post' --post_content='This post is generated automatically for release screenshots.' --porcelain)

if [ -n \"\$first_id\" ]; then
  wp post meta update \$demo_post_id _thumbnail_id \$first_id
fi

if [ -n \"\$second_id\" ]; then
  second_url=\$(wp post get \$second_id --field=guid)
  wp post update \$demo_post_id --post_content=\"<p>Automated screenshot content.</p><p><img src='\$second_url' alt='Demo image'></p>\"
fi
"
