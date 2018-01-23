<?php
class cBlockchain
{
    private $aChain, $iDifficulty;
    
    public function __construct()
    {
        $this->aChain = [$this->createGenesisBlock()];
        $this->iDifficulty = 4;
    }
    
    private function createGenesisBlock()
    {
        return new cBlock(0, strtotime("2018-01-22"), "Genesis Block");
    }
    
    public function getLastBlock(): cBlock
    {
        return $this->aChain[count($this->aChain)-1];
    }
    
    public function getBlockchain(): cBlock
    {
        return $this->aChain;
    }
    
    final private function calculateHashForBlock(cBlock $oBlock)
    {
        return hash("sha256", $oBlock->iIndex.$oBlock->sPrevHash.$oBlock->iTimestamp.((string)$oBlock->sData).$oBlock->iDifficulty.$oBlock->iNonce);
    }
       
    public function isValidNewBlock(cBlock $oNewBlock, cBlock $oPrevBlock)
    {
        if(($oPrevBlock->iIndex + 1) !== $oNewBlock->iIndex)
        {
            return false;
        }
        elseif($oPrevBlock->sHash !== $oNewBlock->sPrevHash)
        {
            return false;
        }
        elseif($this->calculateHashForBlock($oNewBlock) !== $oNewBlock->sHash)
        {
            return false;
        }
        
        return true;
    }
    
    public function isValidChain()
    {
        for($i = 1; $i < count($this->aChain); $i++) 
        {
            if(!isValidNewBlock($this->aChain[$i], $this->aChain[$i - 1]))
            {
                return false;
            }
        }
        return true;
    }
    
    private function replaceChain()
    {
        // TODO: When receiving a valid blockchain
    }
    
    public function generateNextBlock()
    {
        // TODO: New block generation
    }
}
?>