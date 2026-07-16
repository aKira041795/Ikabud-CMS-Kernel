<?php

declare(strict_types=1);

final class EhrWorkbenchComprehensionProvider extends \Ikabud\Kernel\Workbench\Comprehension\ContractComprehensionProvider
{
    public function __construct() { parent::__construct(__DIR__); }
}

return EhrWorkbenchComprehensionProvider::class;
