<?php

declare(strict_types=1);

if(getenv('HARPP_ALLOW_MUTATING_TESTS')!=='1'){fwrite(STDERR,"HARPP_ALLOW_MUTATING_TESTS=1 is required.\n");exit(2);}
$tenant=(int)(getenv('HARPP_ISOLATED_TENANT_ID')?:0);if($tenant<=0){fwrite(STDERR,"HARPP_ISOLATED_TENANT_ID is required.\n");exit(2);}
require dirname(__DIR__,3).'/bootstrap.php';$pdo=app()->dbForTenant($tenant);if(!$pdo instanceof PDO){fwrite(STDERR,"Tenant database is unavailable.\n");exit(2);}$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if(!preg_match('/(?:^|_)(?:test|tmp|ci|sandbox)(?:_|$)/i',$database)){fwrite(STDERR,"Refusing mutating tests: database '$database' is not explicitly isolated.\n");exit(2);}echo "isolated tenant $tenant database $database\n";
