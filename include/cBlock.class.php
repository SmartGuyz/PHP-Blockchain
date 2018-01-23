<?php
class cBlock
{
    public $iIndex, $sHash, $sPrevHash, $iTimestamp, $sData, $iNonce, $iDifficulty;
    
    public function __construct(int $iIndex, int $iTimestamp, string $sData, string $sPrevHash = null)
    {
        $this->iIndex       = (int)$iIndex;      
        $this->sPrevHash    = (string)$sPrevHash;
        $this->iTimestamp   = (int)$iTimestamp;
        $this->sData        = (string)$sData;
        $this->iDifficulty  = 0;
        $this->iNonce       = 0;
        $this->sHash        = (string)$this->calculateHash();
    }
    
    final private function calculateHash()
    {
        return hash("sha256", $this->iIndex.$this->sPrevHash.$this->iTimestamp.((string)$this->sData).$this->iDifficulty.$this->iNonce);
    }
}
?>