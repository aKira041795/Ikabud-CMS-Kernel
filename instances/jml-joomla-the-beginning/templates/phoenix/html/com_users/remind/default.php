<?php
/**
 * Phoenix Template Override - Username Reminder
 * @package     Phoenix
 * @subpackage  com_users
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate');
?>

<div class="phoenix-form-container">
    <div class="phoenix-form-card">
        <?php if ($this->params->get('show_page_heading')) : ?>
            <h1 class="phoenix-form-title">
                <?php echo $this->escape($this->params->get('page_heading')); ?>
            </h1>
        <?php else : ?>
            <h1 class="phoenix-form-title"><?php echo Text::_('COM_USERS_REMIND'); ?></h1>
        <?php endif; ?>
        
        <p class="phoenix-form-description">
            <?php echo Text::_('COM_USERS_REMIND_DESCRIPTION'); ?>
        </p>
        
        <form id="user-remind-form" action="<?php echo Route::_('index.php?option=com_users&task=remind.remind'); ?>" method="post" class="phoenix-form form-validate">
            <?php foreach ($this->form->getFieldsets() as $fieldset) : ?>
                <div class="phoenix-fieldset">
                    <?php if (isset($fieldset->label)) : ?>
                        <h3 class="phoenix-fieldset-legend"><?php echo Text::_($fieldset->label); ?></h3>
                    <?php endif; ?>
                    
                    <div class="phoenix-form-fields">
                        <?php echo $this->form->renderFieldset($fieldset->name); ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div class="phoenix-form-actions">
                <button type="submit" class="phoenix-btn phoenix-btn-primary validate">
                    <?php echo Text::_('JSUBMIT'); ?>
                </button>
            </div>
            
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </div>
</div>

<style>
.phoenix-form-container {
    max-width: 600px;
    margin: 3rem auto;
    padding: 0 1rem;
}

.phoenix-form-card {
    background: white;
    border-radius: 12px;
    padding: 2.5rem;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
}

.phoenix-form-title {
    font-size: 2rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 1rem;
    text-align: center;
}

.phoenix-form-description {
    color: #718096;
    text-align: center;
    margin-bottom: 2rem;
    line-height: 1.6;
}

.phoenix-form .control-group {
    margin-bottom: 1.5rem;
}

.phoenix-form .control-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.phoenix-form .controls input[type="email"],
.phoenix-form .controls input[type="text"] {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.phoenix-form .controls input:focus {
    outline: none;
    border-color: #667eea;
}

.phoenix-fieldset-legend {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 1rem;
}

.phoenix-form-actions {
    margin-top: 2rem;
    text-align: center;
}

.phoenix-btn {
    display: inline-block;
    padding: 0.875rem 2.5rem;
    border: none;
    border-radius: 50px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.phoenix-btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.phoenix-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
}
</style>
