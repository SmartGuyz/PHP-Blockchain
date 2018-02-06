<?php
class cBlockchain extends cP2PServer
{
    use tTransaction, tWallet, tUtils;    
    
    private $aChain, $oGenesisBlock, $cSQLiteBC, $cEC, $aIniValues;
    
    const BLOCK_GENERATION_INTERVAL = 2; // Seconden
    const DIFFICULTY_ADJUSTMENT_INTERVAL = 10; // Blocks
    
    /**
     * Adding genesis block to the chain
     */
    public function __construct(SQLite3 $oSQLiteBC, array $aIniValues)
    {
        // ECSDA
        $this->cEC = $this->instanceECDSA('secp256k1');
        
        // Load SQLite
        $this->cSQLiteBC = $oSQLiteBC;
        
        // Load init values
        $this->aIniValues = $aIniValues;
        
        // Init wallet
        $this->initWallet();
        
        // Generate genesis block
        $this->oGenesisBlock = $this->createGenesisBlock();
        
        self::debug("Starting blockchain...");
        
        $this->loadBlockchain();
        if(empty($this->aChain) OR count($this->aChain) == 0)
        {
            self::debug("DB is empty, so we start a new chain!");
            
            $this->aChain = [$this->oGenesisBlock];
            
            // Add Genisis block to DB
            $this->addBlockToDatabase($this->oGenesisBlock);
        }
        
        self::debug("Local wallet address is: {$this->getPublicFromWallet()}");
    }
    
    private function loadBlockchain()
    {       
        $oSqlChain = $this->cSQLiteBC->query("SELECT * FROM `blockchain` ORDER BY `index`");
        $aSqlChain = $oSqlChain->fetchArray();
        if($aSqlChain !== false)
        {
            self::debug("Start syncing blockchain...");
            while($aSqlChain)
            {
                if($aSqlChain['index'] > 0)
                {
                    $this->addBlockToChain(new cBlock($aSqlChain['index'], $aSqlChain['hash'], $aSqlChain['prevHash'], $aSqlChain['timestamp'], unserialize($aSqlChain['data']), $aSqlChain['difficulty'], $aSqlChain['nonce']));
                }
                else
                {
                    $this->aChain = [new cBlock($aSqlChain['index'], $aSqlChain['hash'], $aSqlChain['prevHash'], $aSqlChain['timestamp'], unserialize($aSqlChain['data']), $aSqlChain['difficulty'], $aSqlChain['nonce'])];
                }
                $aSqlChain = $oSqlChain->fetchArray();
            }
            
            if($this->isValidChain($this->aChain))
            {
                self::debug("Blockchain seems to be valid");
            }
            else
            {
                self::debug("Blockchain seems to be corrupt, start a new one!");
                $this->aChain = [];
            }
            
            self::debug("Blockchain loading done! ".count($this->aChain)." block(s) added to the chain");
        }
    }
    
    private function addBlockToDatabase(cBlock $oBlock)
    {
        $oSqlCheck = $this->cSQLiteBC->query("SELECT * FROM `blockchain` WHERE `index` = '{$oBlock->index}'");
        if(!$oSqlCheck->fetchArray())
        {
            $bCheck = $this->cSQLiteBC->exec("INSERT INTO `blockchain` (`index`, `hash`, `prevHash`, `timestamp`, `data`, `difficulty`, `nonce`) VALUES ('{$oBlock->index}', '{$oBlock->hash}', '{$oBlock->prevHash}', '{$oBlock->timestamp}', '".serialize($oBlock->data)."', '{$oBlock->difficulty}', '{$oBlock->nonce}')");
            if($bCheck)
            {
                //self::debug("Block added to the chain (DB)");
            }
            else
            {
                self::debug("Block NOT added to the chain (DB)");
            }
        }
    }
    
    private function genesisTransaction(): cTransaction
    {
        $oTransaction = new cTransaction();
        $oTransaction->id = '';
        $oTransaction->txIns[0] = new cTxIn();
        $oTransaction->txIns[0]->signature = '';
        $oTransaction->txIns[0]->time = 1516575600;
        $oTransaction->txIns[0]->fromAddress = '';
        $oTransaction->txIns[0]->toAddress = '';
        $oTransaction->txIns[0]->dataObject = new stdClass();
        
        return $oTransaction;
    }
    
    /**
     * Generate the first block of the chain (Genesis)
     * This is the only block without a previous hash in it
     * 
     * @return cBlock
     */
    private function createGenesisBlock(): cBlock
    {
        return new cBlock(0, "0980bd82e10152a1f76aba0935806d58051a47b9e3683cf8062e07ad827bb5a4", "", 1516575600, [$this->genesisTransaction()], 0, 0);
    }
    
    /**
     * Returns an object of the last block in the current chain
     * 
     * @return cBlock
     */
    public function getLastBlock(): cBlock
    {
        return $this->aChain[count($this->aChain) - 1];
    }
    
    /**
     * Returns the whole chain
     * 
     * @return array
     */
    public function getBlockchain(): array
    {
        return $this->aChain;
    }
    
    /**
     * Checks the last block in the chain to see if the difficulty needs te be adjusted
     * If not, it returns the current difficulty
     * 
     * @param array $aBlockchain
     * @return int
     */
    private function getDifficulty(array $aBlockchain): int
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
    
    /**
     * Adjust the difficulty by looking at the last DIFFICULTY_ADJUSTMENT_INTERVAL blocks
     * We either increase or decrease the difficulty by one if the time taken is at least two times greater or smaller than the expected difficulty
     * 
     * @param cBlock $oLastBlock
     * @param array $aBlockchain
     * @return int
     */
    public function getAdjustedDifficulty(cBlock $oLastBlock, array $aBlockchain): int
    {
        $oPrevAdjustmentBlock = (((count($aBlockchain) - self::DIFFICULTY_ADJUSTMENT_INTERVAL) > 0) ? $aBlockchain[count($aBlockchain) - self::DIFFICULTY_ADJUSTMENT_INTERVAL] : $aBlockchain[0]);
        $iTimeExpected = self::BLOCK_GENERATION_INTERVAL * self::DIFFICULTY_ADJUSTMENT_INTERVAL;
        $iTimeTaken = $oLastBlock->timestamp - $oPrevAdjustmentBlock->timestamp;
        
        if($iTimeTaken < ($iTimeExpected / 2))
        {
            return $oPrevAdjustmentBlock->difficulty + 1;
        }
        elseif($iTimeTaken > ($iTimeExpected * 2) && $oPrevAdjustmentBlock->difficulty > 0)
        {
            return $oPrevAdjustmentBlock->difficulty - 1;
        }
        else
        {
            return $oPrevAdjustmentBlock->difficulty;
        }
    }
    
    /**
     * The Proof-of-work puzzle is to find a block hash, that has a specific number of zeros prefixing it. 
     * This function looks if the hash provided matches the difficulty we set
     * 
     * @param string $sHash
     * @param int $iDifficulty
     * @return bool
     */
    private function hashMatchesDifficulty(string $sHash, int $iDifficulty): bool
    {
        if($iDifficulty < 0)
        {
            //self::debug("Difficulty incorrect ({$iDifficulty})");
            return true;
        }
        $sHashInBinary = (string)hex2bin($sHash);
        $sRequiredPrefix = (string)str_repeat('0', $iDifficulty);
        
        return ((substr($sHashInBinary, 0, $iDifficulty) == $sRequiredPrefix) ? true : false);
    }
    
    /**
     * To find a valid block hash we must increase the nonce as until we get a valid hash. 
     * To find a satisfying hash is completely a random process. 
     * We must just loop through enough nonces until we find a satisfying hash
     * 
     * @param int $iIndex
     * @param string $sPrevHash
     * @param int $iTimestamp
     * @param string $sData
     * @param int $iDifficulty
     * @return cBlock
     */
    private function findBlock(int $iIndex, string $sPrevHash, int $iTimestamp, array $aTransactions, int $iDifficulty): cBlock
    {
        $iNonce = 0;
        while(true)
        {
            $sHash = $this->calculateHash($iIndex, $sPrevHash, $iTimestamp, $aTransactions, $iDifficulty, $iNonce);
            if($this->hashMatchesDifficulty($sHash, $iDifficulty))
            {
                return new cBlock($iIndex, $sHash, $sPrevHash, $iTimestamp, $aTransactions, $iDifficulty, $iNonce);
            }
            $iNonce++;
        }
    }
    
    /**
     * Calculate a new hash (SHA256) by feeding it with dynamic information
     * 
     * @param int $iIndex
     * @param string $sPrevHash
     * @param int $iTimestamp
     * @param string $sData
     * @param int $iDifficulty
     * @param int $iNonce
     * @return string
     */
    private function calculateHash(int $iIndex, string $sPrevHash, int $iTimestamp, array $aTransactions, int $iDifficulty, int $iNonce): string
    {
        return hash("sha256", $iIndex.$sPrevHash.$iTimestamp.serialize($aTransactions).$iDifficulty.$iNonce);
    }
    
    /**
     * Re-calculate the hash (SHA256) of an existing block
     * 
     * @param cBlock $oBlock
     * @return string
     */
    private function calculateHashForBlock(cBlock $oBlock): string
    {
        return hash("sha256", $oBlock->index.$oBlock->prevHash.$oBlock->timestamp.serialize($oBlock->data).$oBlock->difficulty.$oBlock->nonce);
    }
    
    /**
     * Checking the structure of a block, so that malformed content sent by a peer won’t crash our node
     * 
     * @param cBlock $oBlock
     * @return bool
     */
    public function isValidBlockStructure(cBlock $oBlock): bool
    {
        if(gettype($oBlock->index) !== "integer")
        {
            return false;
        }
        elseif(gettype($oBlock->hash) !== "string")
        {
            return false;
        }
        elseif(gettype($oBlock->prevHash) !== "string")
        {
            return false;
        }
        elseif(gettype($oBlock->timestamp) !== "integer")
        {
            return false;
        }
        elseif(gettype($oBlock->data) !== "array")
        {
            return false;
        }
        return true;
    }
       
    /**
     * Validating the integrity of blocks
     * 
     * At any given time we must be able to validate if a block or a chain of blocks are valid in terms of integrity. 
     * This is true especially when we receive new blocks from other nodes and must decide whether to accept them or not.
     * 
     * @param cBlock $oNewBlock
     * @param cBlock $oPrevBlock
     * @return bool
     */
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
        elseif(!$this->hasValidHash($oNewBlock))
        {
            return false;
        }
        
        return true;
    }
    
    /**
     * We first check that the first block in the chain matches with the genesis block
     * After that we check the rest of the blocks
     * 
     * @param array $aChain
     * @return bool
     */
    private function isValidChain(array $aChain): bool
    {
        // Anonymous function to quickly check the genesis block
        $isValidGenesis = function(cBlock $oBlock): bool
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
    
    /**
     * To mitigate the attack where a false timestamp is introduced in order to manipulate the difficulty the following rules is introduced:
     *
     * * A block is valid, if the timestamp is at most 1 min in the future from the time we perceive.
     * * A block in the chain is valid, if the timestamp is at most 1 min in the past of the previous block.
     * 
     * @param cBlock $oNewBlock
     * @param cBlock $oPrevBlock
     * @return bool
     */
    private function isValidTimestamp(cBlock $oNewBlock, cBlock $oPrevBlock): bool
    {
        return (((($oPrevBlock->timestamp - 60) < $oNewBlock->timestamp) && ($oNewBlock->timestamp - 60) < $this->getCurrentTimestamp()) ? true : false);
    }

    private function getCurrentTimestamp()
    {
        return time();
    }
    
    /**
     * See if the hash matches the difficulty
     * 
     * @param cBlock $oBlock
     * @return boolean
     */
    private function hasValidHash(cBlock $oBlock): bool
    {
        if(!$this->hashMatchesBlockContent($oBlock)) 
        {
            return false;
        }
        
        if(!$this->hashMatchesDifficulty($oBlock->hash, $oBlock->difficulty))
        {
            // TODO: log
        }
        return true;
    }
    
    /**
     * Checking if the hash is correct for the given content
     * 
     * @param cBlock $oBlock
     * @return bool
     */
    private function hashMatchesBlockContent(cBlock $oBlock): bool
    {
        $sHash = $this->calculateHashForBlock($oBlock);
        return (($sHash === $oBlock->hash) ? true : false);
    }
    
    /**
     * Get accumulated value of the difficulty
     * 
     * @param array $aBlockchain
     * @return int
     */
    private function getAccumulatedDifficulty(array $aBlockchain): int
    {
        $iTotal = 0;
        foreach(array_column($aBlockchain, 'difficulty') AS $iValue)
        {
            $iTotal += pow(2, $iValue);
        }
        return $iTotal;
    }
    
    /**
     * Received blockchain is valid. Replacing current blockchain with received blockchain
     * 
     * Nakamoto consensus
     * 
     * @param array $aNewBlocks
     */
    public function replaceChain(array $aNewBlocks): void
    {
        if($this->isValidChain($aNewBlocks) && $this->getAccumulatedDifficulty($aNewBlocks) > $this->getAccumulatedDifficulty($this->aChain))
        {
            // Clean DB
            $this->cSQLiteBC->exec("DELETE FROM `blockchain`");
            
            // Refill DB
            foreach($aNewBlocks AS $oBlock)
            {
                $this->addBlockToDatabase($oBlock);
            }
            
            // Replace array
            $this->aChain = $aNewBlocks;
            self::debug("Chain has been replaced succesfull");
            
            // Broadcast latest
            parent::broadcastLatest();
        }
    }
    
    /**
     * Generate next block in the chain and add it
     * 
     * @param string $sBlockData
     * @return cBlock
     */
    public function generateNextBlock(): cBlock
    {
        $aTransactionPool = $this->getTransactionPool();
        
        $oPrevBlock = $this->getLastBlock();
        
        $iNextDifficulty = $this->getDifficulty($this->aChain);
        $iNextIndex = ($oPrevBlock->index + 1);
        $iNextTimestamp = $this->getCurrentTimestamp();
        $oNewBlock = $this->findBlock($iNextIndex, $oPrevBlock->hash, $iNextTimestamp, $aTransactionPool, $iNextDifficulty);
        
        $this->addBlockToChain($oNewBlock);
        
        // Broadcast latest
        parent::broadcastLatest();
        
        return $oNewBlock;
    }
    
    /**
     * Add block to the chain
     * 
     * @param cBlock $oNewBlock
     */
    public function addBlockToChain(cBlock $oNewBlock): void
    {
        if($this->isValidNewBlock($oNewBlock, $this->getLastBlock()) === true)
        {
            // Push to blockchain array
            array_push($this->aChain, $oNewBlock);
            
            // Add block to DB
            $this->addBlockToDatabase($oNewBlock);
        }
    }
}
?>