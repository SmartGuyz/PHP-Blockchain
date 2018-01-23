<?php
class cBlockchain
{
    private $aChain, $oGenesisBlock;
    
    const BLOCK_GENERATION_INTERVAL = 10; // Seconden
    const DIFFICULTY_ADJUSTMENT_INTERVAL = 10; // Blocks
    
    public function __construct()
    {
        $this->aChain = [$this->createGenesisBlock()];
    }
    
    private function createGenesisBlock(): cBlock
    {
        $this->oGenesisBlock = new cBlock(0, "0980bd82e10152a1f76aba0935806d58051a47b9e3683cf8062e07ad827bb5a4", "", 1516575600, "Genesis Block", 0, 0);
        return $this->oGenesisBlock;
    }
    
    private function getLastBlock(): cBlock
    {
        return $this->aChain[count($this->aChain) - 1];
    }
    
    public function getBlockchain(): array
    {
        return $this->aChain;
    }
    
    public function getDifficulty($aBlockchain)
    {
        $oLastBlock = $this->getLastBlock();
        if(($oLastBlock->index % self::DIFFICULTY_ADJUSTMENT_INTERVAL) === 0 && $oLastBlock->index <> 0)
        {
            return $this->getAdjustedDifficulty($oLastBlock, $aBlockchain);
        }
        else 
        {
            return $oLastBlock->difficulty;
        }
    }
    
    private function getAdjustedDifficulty($oLastBlock, $aBlockchain)
    {
        $oPrevAdjustmentBlock = (((count($aBlockchain) - self::DIFFICULTY_ADJUSTMENT_INTERVAL) > 0) ? $aBlockchain[count($aBlockchain) - self::DIFFICULTY_ADJUSTMENT_INTERVAL] : $aBlockchain[0]);
        $iTimeExpected = self::BLOCK_GENERATION_INTERVAL * self::DIFFICULTY_ADJUSTMENT_INTERVAL;
        $iTimeTaken = $oLastBlock->timestamp - $oPrevAdjustmentBlock->timestamp;
        
        if($iTimeTaken < ($iTimeExpected / 2))
        {
            return $oPrevAdjustmentBlock->difficulty + 1;
        }
        elseif($iTimeTaken > ($iTimeExpected * 2))
        {
            return $oPrevAdjustmentBlock->difficulty - 1;
        }
        else
        {
            return $oPrevAdjustmentBlock->difficulty;
        }
    }
    
    private function hashMatchesDifficulty($sHash, $iDifficulty)
    {
        $sHashInBinary = (string)hex2bin($sHash);
        $sRequiredPrefix = (string)str_repeat('0', $iDifficulty);
        
        return ((substr($sHashInBinary, 0, $iDifficulty) == $sRequiredPrefix) ? true : false);
    }
    
    private function findBlock($iIndex, $sPrevHash, $iTimestamp, $sData, $iDifficulty)
    {
        $iNonce = 0;
        while(true)
        {
            $sHash = $this->calculateHash($iIndex, $sPrevHash, $iTimestamp, $sData, $iDifficulty, $iNonce);
            if($this->hashMatchesDifficulty($sHash, $iDifficulty))
            {
                return new cBlock($iIndex, $sHash, $sPrevHash, $iTimestamp, $sData, $iDifficulty, $iNonce);
            }
            $iNonce++;
        }
    }
    
    private function calculateHash(int $iIndex, string $sPrevHash, int $iTimestamp, string $sData, int $iDifficulty, int $iNonce): string
    {
        return hash("sha256", $iIndex.$sPrevHash.$iTimestamp.$sData.$iDifficulty.$iNonce);
    }
    
    private function calculateHashForBlock(cBlock $oBlock): string
    {
        return hash("sha256", $oBlock->index.$oBlock->prevHash.$oBlock->timestamp.$oBlock->data.$oBlock->difficulty.$oBlock->nonce);
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
        elseif(($oPrevBlock->index + 1) !== $oNewBlock->index)
        {
            return false;
        }
        elseif($oPrevBlock->hash !== $oNewBlock->prevHash)
        {
            return false;
        }
        elseif(!$this->isValidTimestamp($oNewBlock, $oPrevBlock))
        {
            return false;
        }
        elseif($this->calculateHashForBlock($oNewBlock) !== $oNewBlock->hash)
        {
            return false;
        }
        
        return true;
    }
    
    private function isValidChain($aChain): bool
    {
        $isValidGenesis = function(cBlock $oBlock)
        {
             return json_encode($oBlock) === json_encode($this->oGenesisBlock); 
        };
        
        if(!$isValidGenesis($aChain[0])) 
        {
            return false;
        }
        
        for($i = 1; $i < count($aChain); $i++) 
        {
            if(!$this->isValidNewBlock($aChain[$i], $aChain[$i - 1]))
            {
                return false;
            }
        }
        return true;
    }
    
    private function isValidTimestamp($oNewBlock, $oPrevBlock)
    {
        return ($oPrevBlock->timestamp - 60 < $oNewBlock->timestamp) && $oNewBlock->timestamp - 60 < $this->getCurrentTimestamp();
    }

    private function getCurrentTimestamp()
    {
        return time();
    }
    
    private function hasValidHash(cBlock $oBlock)
    {
        if(!$this->hashMatchesDifficulty($oBlock->hash, $oBlock->difficulty))
        {
            // TODO: log
        }
        return true;
    }
    
    private function hashMatchesBlockContent(cBlock $oBlock)
    {
        $sHash = $this->calculateHashForBlock($oBlock);
        return $sHash === $oBlock->hash;
    }
    
    private function replaceChain(array $aNewBlocks)
    {
        // Received blockchain is valid. Replacing current blockchain with received blockchain
        // Nakamoto consensus
        if($this->isValidChain($aNewBlocks) && $this->getAccumulatedDifficulty($aNewBlocks) > $this->getAccumulatedDifficulty($this->aChain))
        {
            $this->aChain = $aNewBlocks;
            // TODO: Broadcast latest
        }
    }
    
    public function generateNextBlock(string $sBlockData): cBlock
    {
        $oPrevBlock = $this->getLastBlock();
        
        $iNextDifficulty = $this->getDifficulty($this->aChain);
        $iNextIndex = ($oPrevBlock->index + 1);
        $iNextTimestamp = $this->getCurrentTimestamp();
        $oNewBlock = $this->findBlock($iNextIndex, $oPrevBlock->hash, $iNextTimestamp, $sBlockData, $iNextDifficulty);
        
        $this->addBlock($oNewBlock);
        
        // TODO: Broadcast latest
        
        return $oNewBlock;
    }
    
    public function addBlock(cBlock $oNewBlock)
    {
        if($this->isValidNewBlock($oNewBlock, $this->getLastBlock()))
        {
            array_push($this->aChain, $oNewBlock);
        }
    }
}
?>