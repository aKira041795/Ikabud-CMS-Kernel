<?php
/**
 * Phoenix Template - Error Page
 * 
 * @package     Phoenix
 * @version     1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

$app = Factory::getApplication();
$wa  = $this->getWebAssetManager();

// Get error details
$error = $this->error->getCode();
$message = $this->error->getMessage();

// Register assets
$wa->registerAndUseStyle('phoenix.style', 'templates/' . $this->template . '/assets/css/style.css', [], ['version' => '1.0.0']);

?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $error; ?> - <?php echo htmlspecialchars($app->get('sitename')); ?></title>
    <jdoc:include type="styles" />
    <style>
        .error-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 2rem;
        }
        .error-content {
            max-width: 600px;
        }
        .error-code {
            font-size: 8rem;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-message {
            font-size: 1.5rem;
            margin-bottom: 2rem;
        }
        .error-button {
            display: inline-block;
            padding: 1rem 2rem;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            transition: transform 0.3s ease;
        }
        .error-button:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="error-page">
    <div class="error-content">
        <div class="error-code"><?php echo $error; ?></div>
        <div class="error-message">
            <?php if ($error == 404): ?>
                <?php echo Text::_('JERROR_LAYOUT_PAGE_NOT_FOUND'); ?>
            <?php elseif ($error == 403): ?>
                <?php echo Text::_('JERROR_ALERTNOAUTHOR'); ?>
            <?php else: ?>
                <?php echo htmlspecialchars($message); ?>
            <?php endif; ?>
        </div>
        <a href="<?php echo Uri::base(); ?>" class="error-button">
            <?php echo Text::_('JERROR_LAYOUT_GO_TO_THE_HOME_PAGE'); ?>
        </a>
    </div>
</body>
</html>
