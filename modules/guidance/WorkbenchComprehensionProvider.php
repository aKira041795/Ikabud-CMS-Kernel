<?php

declare(strict_types=1);

final class GuidanceWorkbenchComprehensionProvider extends \Ikabud\Kernel\Workbench\Comprehension\ContractComprehensionProvider
{
    public function __construct() { parent::__construct(__DIR__); }
}

return GuidanceWorkbenchComprehensionProvider::class;
