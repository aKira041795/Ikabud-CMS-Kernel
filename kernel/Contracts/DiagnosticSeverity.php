<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

enum DiagnosticSeverity: string
{
    case Fatal = 'fatal';
    case CertificationBlocker = 'cert_blocker';
    case Advisory = 'advisory';
}
