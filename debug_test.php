<?php
require 'bootstrap.php';
$db = cmsDb();
var_dump(cmsActiveThemeManifest());
var_dump(cmsActiveCustomizerScope());
var_dump(cmsCustomizerGet($db, 'entity_presentation'));
