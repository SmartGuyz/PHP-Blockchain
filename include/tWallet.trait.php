<?php
trait tWallet
{
    private $rWalletFile;
    
    private function initWallet(): void
    {
        // Let's not overwrite an existing private key
        if(file_exists($this->aIniValues['database']['datafile_wallet']))
        {
            return;
        }
        
        $sNewPrivateKey = $this->generatePrivateKey();
        file_put_contents($this->aIniValues['database']['datafile_wallet'], $sNewPrivateKey, LOCK_EX);
        
        self::debug("new wallet with private key created to: {$this->aIniValues['database']['datafile_wallet']}");
    }
    
    private function generatePrivateKey(): string
    {
        $oKeyPair = $this->cEC->genKeyPair();
        $oPrivateKey = $oKeyPair->getPrivate();
        
        return (string)$oPrivateKey->toString(16);
    }
    
    private function getPrivateFromWallet(): string
    {
        $sBuffer = file_get_contents($this->aIniValues['database']['datafile_wallet']);
        return (string)$sBuffer;
    }
    
    public function getPublicFromWallet(): string
    {
        $sPrivateKey = $this->getPrivateFromWallet();
        $oKey = $this->cEC->keyFromPrivate($sPrivateKey, 'hex');
        return $oKey->getPublic(false, 'hex');
    }
}
?>