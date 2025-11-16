<?php
/**
 * Phoenix Template - Offline Page
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

// Register assets
$wa->registerAndUseStyle('phoenix.style', 'templates/' . $this->template . '/assets/css/style.css', [], ['version' => '1.0.0']);

?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($app->get('sitename')); ?> - <?php echo Text::_('JOFFLINE'); ?></title>
    <jdoc:include type="styles" />
    <style>
        .offline-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 2rem;
        }
        .offline-content {
            max-width: 600px;
        }
        .offline-icon {
            font-size: 5rem;
            margin-bottom: 2rem;
        }
        .offline-title {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 1rem;
        }
        .offline-message {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        .offline-form {
            background: rgba(255, 255, 255, 0.1);
            padding: 2rem;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body class="offline-page">
    <div class="offline-content">
        <div class="offline-icon">🔧</div>
        <h1 class="offline-title"><?php echo htmlspecialchars($app->get('sitename')); ?></h1>
        <div class="offline-message">
            <?php echo htmlspecialchars($app->get('offline_message')); ?>
        </div>
        
        <?php if ($app->get('offline_image') && file_exists($app->get('offline_image'))) : ?>
            <img src="<?php echo Uri::root() . $app->get('offline_image'); ?>" alt="<?php echo htmlspecialchars($app->get('sitename')); ?>" />
        <?php endif; ?>
        
        <div class="offline-form">
            <jdoc:include type="message" />
            <form action="<?php echo Uri::base(); ?>index.php" method="post" id="form-login">
                <jdoc:include type="component" />
            </form>
        </div>
    </div>
</body>
</html>
