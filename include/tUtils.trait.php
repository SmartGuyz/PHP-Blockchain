<?php
trait tUtils
{
    private static function debug($sData)
    {
        echo " * {$sData}".PHP_EOL;
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