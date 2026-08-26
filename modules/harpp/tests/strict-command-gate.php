<?php

declare(strict_types=1);

if ($argc < 2) { fwrite(STDERR,"Usage: php strict-command-gate.php command [args...]\n"); exit(2); }
$command=array_slice($argv,1);$descriptors=[1=>['pipe','w'],2=>['pipe','w']];$process=proc_open($command,$descriptors,$pipes,null,null,['bypass_shell'=>true]);
if(!is_resource($process)){fwrite(STDERR,"Unable to start command.\n");exit(2);}
$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$combined=$stdout."\n".$stderr;
fwrite(STDOUT,$stdout);fwrite(STDERR,$stderr);
$bad='/<!doctype\s+html|<html\b|HTTP\/\S+\s+5\d\d|\b(?:PHP\s+)?(?:warning|fatal error|parse error)\b|operation not permitted|sandbox denial/i';
if($exit!==0){fwrite(STDERR,"Strict gate: non-zero exit $exit.\n");exit(1);}if(preg_match($bad,$combined,$match)){fwrite(STDERR,"Strict gate: rejected output marker: {$match[0]}\n");exit(1);}exit(0);
