#!/bin/bash
echo "Copying templates from guidance/templates to templates/modules/guidance/"
mkdir -p templates/modules/guidance/
cp -R guidance/templates/* templates/modules/guidance/

echo "Injecting PRO tier boundaries into template sidebars..."
find templates/modules/guidance/ -type f -name "*.disyl" -exec sed -i -e "s/{if user.role == 'admin'}/{if guidanceIsPro() && user.role == 'admin'}/g" {} \;

echo "Templates copied."
