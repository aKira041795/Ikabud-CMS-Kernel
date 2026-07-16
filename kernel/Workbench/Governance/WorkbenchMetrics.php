<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Governance;
final class WorkbenchMetrics
{
    public function __construct(private readonly string $file) {}
    public function record(string $metric, array $labels = [], float $value = 1.0): void
    {
        $dir=dirname($this->file); if(!is_dir($dir))@mkdir($dir,0770,true); $lock=fopen($this->file.'.lock','c+'); if(!$lock||!flock($lock,LOCK_EX))return;
        try { $data=is_file($this->file)?json_decode((string)file_get_contents($this->file),true):[]; if(!is_array($data))$data=[]; $key=$metric.'|'.hash('sha256',json_encode($labels)); $data[$key]??=['metric'=>$metric,'labels'=>$labels,'count'=>0,'sum'=>0.0,'last_seen'=>null]; $data[$key]['count']++; $data[$key]['sum']+=$value; $data[$key]['last_seen']=date('c'); file_put_contents($this->file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX); } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function snapshot(): array { $v=is_file($this->file)?json_decode((string)file_get_contents($this->file),true):[]; return is_array($v)?array_values($v):[]; }
}
