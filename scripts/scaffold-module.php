<?php

declare(strict_types=1);

fwrite(
    STDERR,
    "ERROR: scripts/scaffold-module.php is deprecated and no longer creates modules.\n"
    . "Use the canonical scaffolder instead:\n"
    . "  php ikabud make:module <module-id>\n"
);

exit(1);
