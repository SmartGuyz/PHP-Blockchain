<?php
trait tUtils
{
    private static function debug($sData)
    {
        $t = microtime(true);
        $micro = sprintf("%06d",($t - floor($t)) * 1000000);
        $d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t) );
        
        echo "[".$d->format("H:i:s.u")."] * {$sData}".PHP_EOL;
    }
    
    private function instanceECDSA(string $sPreset = 'secp256k1')
    {
        // Switch to composer autoloader
        spl_autoload_unregister('default_autoloader');
        require_once __DIR__ . "/../vendor/autoload.php";
        
        $cEC = new \Elliptic\EC($sPreset);
        
        // Switch to default autoloader
        spl_autoload_register('default_autoloader');
        
        return $cEC;
    }
}
?>