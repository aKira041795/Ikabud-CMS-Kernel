<?php
/**
 * Phoenix Template - Component View
 * 
 * Used for component-only output (no template chrome)
 * 
 * @package     Phoenix
 * @version     1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

$app = Factory::getApplication();
$wa  = $this->getWebAssetManager();

// Register minimal assets for component view
$wa->registerAndUseStyle('phoenix.component', 'templates/' . $this->template . '/assets/css/style.css', [], ['version' => '1.0.0']);

?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
    <jdoc:include type="metas" />
    <jdoc:include type="styles" />
    <jdoc:include type="scripts" />
</head>
<body class="contentpane component">
    <jdoc:include type="message" />
    <jdoc:include type="component" />
</body>
</html>
