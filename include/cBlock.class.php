<?php
/**
 * Object for each block. this object will be added to the chain
 *
 */
class cBlock
{
    public $index, $hash, $prevHash, $timestamp, $data, $nonce, $difficulty;
    
    public function __construct(int $iIndex, string $sHash, string $sPrevHash, int $iTimestamp, string $sData, int $iDifficulty, int $iNonce)
    {
        $this->index       = (int)$iIndex;
        $this->hash        = (string)$sHash;
        $this->prevHash    = (string)$sPrevHash;
        $this->timestamp   = (int)$iTimestamp;
        $this->data        = (string)$sData;
        $this->difficulty  = (int)$iDifficulty;
        $this->nonce       = (int)$iNonce;  
    }
}
?>