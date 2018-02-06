<?php
class cBlock
{
    public $index, $hash, $prevHash, $timestamp, $data, $nonce, $difficulty;
    
    public function __construct(int $iIndex, string $sHash, string $sPrevHash, int $iTimestamp, array $aTransactions, int $iDifficulty, int $iNonce)
    {
        $this->index       = (int)$iIndex;
        $this->hash        = (string)$sHash;
        $this->prevHash    = (string)$sPrevHash;
        $this->timestamp   = (int)$iTimestamp;
        $this->data        = (array)$aTransactions;
        $this->difficulty  = (int)$iDifficulty;
        $this->nonce       = (int)$iNonce;  
    }
}
?>