<?php
/**
 * SMS Module Routes
 * 
 * Handler format: "sms:functionName" — the module loader will
 * require handlers.php and call the named function.
 * 
 * @package Baron\Modules\SMS
 */

return [
    'GET' => [
        '/admin/sms'                         => 'sms:pageSmsLog',
        '/admin/sms/compose'                 => 'sms:pageSmsCompose',
        '/admin/sms/templates'               => 'sms:pageSmsTemplates',
        '/admin/sms/settings'                => 'sms:pageSmsSettings',

        '/api/v1/modules/sms/log'            => 'sms:apiSmsLog',
        '/api/v1/modules/sms/stats'          => 'sms:apiSmsStats',
        '/api/v1/modules/sms/balance'        => 'sms:apiSmsBalance',
        '/api/v1/modules/sms/templates'      => 'sms:apiSmsTemplates',
    ],
    'POST' => [
        '/api/v1/modules/sms/send'           => 'sms:apiSmsSend',
        '/api/v1/modules/sms/test'           => 'sms:apiSmsTest',
        '/api/v1/modules/sms/templates'      => 'sms:apiSmsTemplateSave',
        '/api/v1/modules/sms/settings'       => 'sms:apiSmsSettingsSave',
    ],
];
