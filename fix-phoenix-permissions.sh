#!/bin/bash
# Fix Phoenix Template Permissions
# Run this script whenever you see "template folder is not writable"

echo "Fixing Phoenix template permissions..."

PHOENIX_PATH="/var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix"

# Set ownership to web server user
sudo chown -R www-data:www-data "$PHOENIX_PATH"

# Set permissions: 775 for directories and files (writable by owner and group)
sudo chmod -R 775 "$PHOENIX_PATH"

# Verify
echo ""
echo "Current permissions:"
ls -ld "$PHOENIX_PATH"

echo ""
echo "✅ Permissions fixed!"
echo ""
echo "Template folder is now:"
echo "  - Owner: www-data (web server)"
echo "  - Group: www-data"
echo "  - Permissions: 775 (rwxrwxr-x)"
echo "  - Writable: YES"
