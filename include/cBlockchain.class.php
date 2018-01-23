<?php
class cBlockchain
{
    private $aChain, $iDifficulty, $iNonce;
    
    const BLOCK_GENERATION_INTERVAL = 10;
    const DIFFICULTY_ADJUSTMENT_INTERVAL = 10;
    
    public function __construct()
    {
        $this->aChain = [$this->createGenesisBlock()];
        $this->iDifficulty = 4;
        $this->iNonce = 0;
    }
    
    private function createGenesisBlock(): cBlock
    {
        return new cBlock(0, "0980bd82e10152a1f76aba0935806d58051a47b9e3683cf8062e07ad827bb5a4", "", 1516575600, "Genesis Block", 0, 0);
    }
    
    private function getLastBlock(): cBlock
    {
        return $this->aChain[count($this->aChain) - 1];
    }
    
    public function getBlockchain(): array
    {
        return $this->aChain;
    }
    
    private function calculateHash(int $iIndex, string $sPrevHash, int $iTimestamp, string $sData, int $iDifficulty, int $iNonce): string
    {
        return hash("sha256", $iIndex.$sPrevHash.$iTimestamp.$sData.$iDifficulty.$iNonce);
    }
    
    private function calculateHashForBlock(cBlock $oBlock): string
    {
        return hash("sha256", $oBlock->iIndex.$oBlock->sPrevHash.$oBlock->iTimestamp.((string)$oBlock->sData).$oBlock->iDifficulty.$oBlock->iNonce);
    }
    
    private function isValidBlockStructure(cBlock $oBlock): bool
    {
        if(!gettype($oBlock->index) == "integer")
        {
            return false;
        }
        elseif(!gettype($oBlock->hash) == "string")
        {
            return false;
        }
        elseif(!gettype($oBlock->prevHash) == "string")
        {
            return false;
        }
        elseif(!gettype($oBlock->timestamp) == "integer")
        {
            return false;
        }
        elseif(!gettype($oBlock->data) == "string")
        {
            return false;
        }
        return true;
    }
       
    private function isValidNewBlock(cBlock $oNewBlock, cBlock $oPrevBlock): bool
    {
        if(!$this->isValidBlockStructure($oNewBlock))
        {
            return false;
        }
        elseif(($oPrevBlock->iIndex + 1) !== $oNewBlock->iIndex)
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
    
    private function isValidChain($aChain): bool
    {
        for($i = 1; $i < count($aChain); $i++) 
        {
            if(!$this->isValidNewBlock($aChain[$i], $aChain[$i - 1]))
            {
                return false;
            }
        }
        return true;
    }
    
    private function replaceChain(array $aNewBlocks)
    {
        if($this->isValidChain($aNewBlocks) && count($aNewBlocks) > count($this->getBlockchain()))
        {
            $this->aChain = $aNewBlocks;
            // TODO: Broadcast latest
        }
    }
    
    public function generateNextBlock(string $sBlockData): cBlock
    {
        $oPrevBlock = $this->getLastBlock();
        
        $iNextIndex = ($oPrevBlock->index + 1);
        $iNextTimestamp = time();
        $sNextHash = $this->calculateHash($iNextIndex, $oPrevBlock->hash, $iNextTimestamp, $sBlockData, $this->iDifficulty, $this->iNonce);
        $oNewBlock = new cBlock($iNextIndex, $sNextHash, $oPrevBlock->hash, $iNextTimestamp, $sBlockData, $this->iDifficulty, $this->iNonce);
        
        $this->addBlock($oNewBlock);
        
        // TODO: Broadcast latest
        
        return $oNewBlock;
    }
    
    public function addBlock(cBlock $oNewBlock)
    {
        if($this->isValidNewBlock($oNewBlock, $this->getLastBlock()))
        {
            $this->aChain = [$oNewBlock];
        }
    }
}
?>