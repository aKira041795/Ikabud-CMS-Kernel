<?php

declare(strict_types=1);

final class WmsWorkbenchComprehensionProvider extends \Ikabud\Kernel\Workbench\Comprehension\ContractComprehensionProvider
{
    public function __construct() { parent::__construct(__DIR__); }
}

return WmsWorkbenchComprehensionProvider::class;
