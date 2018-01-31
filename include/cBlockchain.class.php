<?php
class cBlockchain extends cP2PServer
{
    use tUtils;
    
    private $aChain, $oGenesisBlock, $cSQLiteBC;
    
    const BLOCK_GENERATION_INTERVAL = 10; // Seconden
    const DIFFICULTY_ADJUSTMENT_INTERVAL = 10; // Blocks
    
    /**
     * Adding genesis block to the chain
     */
    public function __construct(SQLite3 $oSQLiteBC)
    {
        $this->cSQLiteBC = $oSQLiteBC;
        
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
                    $this->addBlockToChain(new cBlock($aSqlChain['index'], $aSqlChain['hash'], $aSqlChain['prevHash'], $aSqlChain['timestamp'], $aSqlChain['data'], $aSqlChain['difficulty'], $aSqlChain['nonce']));
                }
                else
                {
                    $this->aChain = [new cBlock($aSqlChain['index'], $aSqlChain['hash'], $aSqlChain['prevHash'], $aSqlChain['timestamp'], $aSqlChain['data'], $aSqlChain['difficulty'], $aSqlChain['nonce'])];
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
            $bCheck = $this->cSQLiteBC->exec("INSERT INTO `blockchain` (`index`, `hash`, `prevHash`, `timestamp`, `data`, `difficulty`, `nonce`) VALUES ('{$oBlock->index}', '{$oBlock->hash}', '{$oBlock->prevHash}', '{$oBlock->timestamp}', '{$oBlock->data}', '{$oBlock->difficulty}', '{$oBlock->nonce}')");
            if($bCheck)
            {
                self::debug("Block added to the chain (DB)");
            }
            else
            {
                self::debug("Block NOT added to the chain (DB)");
            }
        }
    }
    
    /**
     * Generate the first block of the chain (Genesis)
     * This is the only block without a previous hash in it
     * 
     * @return cBlock
     */
    private function createGenesisBlock(): cBlock
    {
        return new cBlock(0, "0980bd82e10152a1f76aba0935806d58051a47b9e3683cf8062e07ad827bb5a4", "", 1516575600, "Genesis Block", 0, 0);
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
    private function findBlock(int $iIndex, string $sPrevHash, int $iTimestamp, string $sData, int $iDifficulty): cBlock
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
    private function calculateHash(int $iIndex, string $sPrevHash, int $iTimestamp, string $sData, int $iDifficulty, int $iNonce): string
    {
        return hash("sha256", $iIndex.$sPrevHash.$iTimestamp.$sData.$iDifficulty.$iNonce);
    }
    
    /**
     * Re-calculate the hash (SHA256) of an existing block
     * 
     * @param cBlock $oBlock
     * @return string
     */
    private function calculateHashForBlock(cBlock $oBlock): string
    {
        return hash("sha256", $oBlock->index.$oBlock->prevHash.$oBlock->timestamp.$oBlock->data.$oBlock->difficulty.$oBlock->nonce);
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
        elseif(gettype($oBlock->data) !== "string")
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
    public function generateNextBlock(string $sBlockData): cBlock
    {
        $oPrevBlock = $this->getLastBlock();
        
        $iNextDifficulty = $this->getDifficulty($this->aChain);
        $iNextIndex = ($oPrevBlock->index + 1);
        $iNextTimestamp = $this->getCurrentTimestamp();
        $oNewBlock = $this->findBlock($iNextIndex, $oPrevBlock->hash, $iNextTimestamp, $sBlockData, $iNextDifficulty);
        
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
    
    /*
     * 
     * TRANSACTION SECTION STARTS HERE
     * 
     */
    private function getTransactionId(cTransaction $oTransaction): string
    {
        $sTxInContent = join(array_map(function($oTxIns) { return $oTxIns->txOutId.$oTxIns->txOutIndex; }, $oTransaction->txIns));
        $sTxOutContent = join(array_map(function($oTxOuts) { return $oTxOuts->address.$oTxOuts->amount; }, $oTransaction->txOuts));
        
        return hash('sha256', (string)$sTxInContent.(string)$sTxOutContent);
    }
    
    private function validateTransaction(cTransaction $oTransaction, array $aUnspentTxOut) : bool
    {
        if(!$this->isValidTransactionStructure($oTransaction))
        {
            return false;
        }
        elseif($this->getTransactionId($oTransaction) != $oTransaction->id)
        {
            return false;
        }
        
        $bHasValidTxIns = array_reduce(array_map(function($oTxIns) use ($oTransaction, $aUnspentTxOut) { return $this->validateTxIn($oTxIns, $oTransaction, $aUnspentTxOut); }, $oTransaction->txIns), function($a, $b) { return $a && $b; }, true);
        
        /*
         * const validateTransaction = (transaction: Transaction, aUnspentTxOuts: UnspentTxOut[]): boolean => {

    const hasValidTxIns: boolean = transaction.txIns
        .map((txIn) => validateTxIn(txIn, transaction, aUnspentTxOuts))
        .reduce((a, b) => a && b, true);

    if (!hasValidTxIns) {
        console.log('some of the txIns are invalid in tx: ' + transaction.id);
        return false;
    }

    const totalTxInValues: number = transaction.txIns
        .map((txIn) => getTxInAmount(txIn, aUnspentTxOuts))
        .reduce((a, b) => (a + b), 0);

    const totalTxOutValues: number = transaction.txOuts
        .map((txOut) => txOut.amount)
        .reduce((a, b) => (a + b), 0);

    if (totalTxOutValues !== totalTxInValues) {
        console.log('totalTxOutValues !== totalTxInValues in tx: ' + transaction.id);
        return false;
    }

    return true;
};
         */
    }
    
    private function isValidTransactionStructure(cTransaction $oTransaction) 
    {
        if(gettype($oTransaction->id) !== "string")
        {
            return false;
        }
        elseif(gettype($oTransaction->txIns) !== "array")
        {
            return false;
        }
        elseif(gettype($oTransaction->txOuts) !== "array")
        {
            return false;
        }
        elseif(!array_reduce(array_map([$this, 'isValidTxInStructure'], $oTransaction->txIns), function($a, $b) { return $a && $b; }, true))
        {
            return false;
        }
        elseif(!array_reduce(array_map([$this, 'isValidTxInStructure'], $oTransaction->txOuts), function($a, $b) { return $a && $b; }, true))
        {
            return false;
        }
        return true;
    }
    
    private function isValidTxInStructure(cTxIn $oTxIn)
    {
        if($oTxIn == null)
        {
            return false;
        }
        elseif(gettype($oTxIn->signature) !== "string")
        {
            return false;
        }
        elseif(gettype($oTxIn->txOutId) !== "string")
        {
            return false;
        }
        elseif(gettype($oTxIn->txOutIndex) !== "integer")
        {
            return false;
        }
        return true;
    }
    
    private function validateTxIn(cTxIn $oTxIn, cTransaction $oTransaction, array $aUnspentTxOut)
    {
        /*
         * const validateTxIn = (txIn: TxIn, transaction: Transaction, aUnspentTxOuts: UnspentTxOut[]): boolean => {
    const referencedUTxOut: UnspentTxOut =
        aUnspentTxOuts.find((uTxO) => uTxO.txOutId === txIn.txOutId && uTxO.txOutIndex === txIn.txOutIndex);
    if (referencedUTxOut == null) {
        console.log('referenced txOut not found: ' + JSON.stringify(txIn));
        return false;
    }
    const address = referencedUTxOut.address;

    const key = ec.keyFromPublic(address, 'hex');
    const validSignature: boolean = key.verify(transaction.id, txIn.signature);
    if (!validSignature) {
        console.log('invalid txIn signature: %s txId: %s address: %s', txIn.signature, transaction.id, referencedUTxOut.address);
        return false;
    }
    return true;
};
         */
    }
    
    /*
const validateBlockTransactions = (aTransactions: Transaction[], aUnspentTxOuts: UnspentTxOut[], blockIndex: number): boolean => {
    const coinbaseTx = aTransactions[0];
    if (!validateCoinbaseTx(coinbaseTx, blockIndex)) {
        console.log('invalid coinbase transaction: ' + JSON.stringify(coinbaseTx));
        return false;
    }

    // check for duplicate txIns. Each txIn can be included only once
    const txIns: TxIn[] = _(aTransactions)
        .map((tx) => tx.txIns)
        .flatten()
        .value();

    if (hasDuplicates(txIns)) {
        return false;
    }

    // all but coinbase transactions
    const normalTransactions: Transaction[] = aTransactions.slice(1);
    return normalTransactions.map((tx) => validateTransaction(tx, aUnspentTxOuts))
        .reduce((a, b) => (a && b), true);

};

const hasDuplicates = (txIns: TxIn[]): boolean => {
    const groups = _.countBy(txIns, (txIn: TxIn) => txIn.txOutId + txIn.txOutIndex);
    return _(groups)
        .map((value, key) => {
            if (value > 1) {
                console.log('duplicate txIn: ' + key);
                return true;
            } else {
                return false;
            }
        })
        .includes(true);
};

const validateCoinbaseTx = (transaction: Transaction, blockIndex: number): boolean => {
    if (transaction == null) {
        console.log('the first transaction in the block must be coinbase transaction');
        return false;
    }
    if (getTransactionId(transaction) !== transaction.id) {
        console.log('invalid coinbase tx id: ' + transaction.id);
        return false;
    }
    if (transaction.txIns.length !== 1) {
        console.log('one txIn must be specified in the coinbase transaction');
        return;
    }
    if (transaction.txIns[0].txOutIndex !== blockIndex) {
        console.log('the txIn signature in coinbase tx must be the block height');
        return false;
    }
    if (transaction.txOuts.length !== 1) {
        console.log('invalid number of txOuts in coinbase transaction');
        return false;
    }
    if (transaction.txOuts[0].amount !== COINBASE_AMOUNT) {
        console.log('invalid coinbase amount in coinbase transaction');
        return false;
    }
    return true;
};

const getTxInAmount = (txIn: TxIn, aUnspentTxOuts: UnspentTxOut[]): number => {
    return findUnspentTxOut(txIn.txOutId, txIn.txOutIndex, aUnspentTxOuts).amount;
};

const findUnspentTxOut = (transactionId: string, index: number, aUnspentTxOuts: UnspentTxOut[]): UnspentTxOut => {
    return aUnspentTxOuts.find((uTxO) => uTxO.txOutId === transactionId && uTxO.txOutIndex === index);
};

const getCoinbaseTransaction = (address: string, blockIndex: number): Transaction => {
    const t = new Transaction();
    const txIn: TxIn = new TxIn();
    txIn.signature = '';
    txIn.txOutId = '';
    txIn.txOutIndex = blockIndex;

    t.txIns = [txIn];
    t.txOuts = [new TxOut(address, COINBASE_AMOUNT)];
    t.id = getTransactionId(t);
    return t;
};

const signTxIn = (transaction: Transaction, txInIndex: number,
                  privateKey: string, aUnspentTxOuts: UnspentTxOut[]): string => {
    const txIn: TxIn = transaction.txIns[txInIndex];

    const dataToSign = transaction.id;
    const referencedUnspentTxOut: UnspentTxOut = findUnspentTxOut(txIn.txOutId, txIn.txOutIndex, aUnspentTxOuts);
    if (referencedUnspentTxOut == null) {
        console.log('could not find referenced txOut');
        throw Error();
    }
    const referencedAddress = referencedUnspentTxOut.address;

    if (getPublicKey(privateKey) !== referencedAddress) {
        console.log('trying to sign an input with private' +
            ' key that does not match the address that is referenced in txIn');
        throw Error();
    }
    const key = ec.keyFromPrivate(privateKey, 'hex');
    const signature: string = toHexString(key.sign(dataToSign).toDER());

    return signature;
};

const updateUnspentTxOuts = (aTransactions: Transaction[], aUnspentTxOuts: UnspentTxOut[]): UnspentTxOut[] => {
    const newUnspentTxOuts: UnspentTxOut[] = aTransactions
        .map((t) => {
            return t.txOuts.map((txOut, index) => new UnspentTxOut(t.id, index, txOut.address, txOut.amount));
        })
        .reduce((a, b) => a.concat(b), []);

    const consumedTxOuts: UnspentTxOut[] = aTransactions
        .map((t) => t.txIns)
        .reduce((a, b) => a.concat(b), [])
        .map((txIn) => new UnspentTxOut(txIn.txOutId, txIn.txOutIndex, '', 0));

    const resultingUnspentTxOuts = aUnspentTxOuts
        .filter(((uTxO) => !findUnspentTxOut(uTxO.txOutId, uTxO.txOutIndex, consumedTxOuts)))
        .concat(newUnspentTxOuts);

    return resultingUnspentTxOuts;
};

const processTransactions = (aTransactions: Transaction[], aUnspentTxOuts: UnspentTxOut[], blockIndex: number) => {

    if (!validateBlockTransactions(aTransactions, aUnspentTxOuts, blockIndex)) {
        console.log('invalid block transactions');
        return null;
    }
    return updateUnspentTxOuts(aTransactions, aUnspentTxOuts);
};

const toHexString = (byteArray): string => {
    return Array.from(byteArray, (byte: any) => {
        return ('0' + (byte & 0xFF).toString(16)).slice(-2);
    }).join('');
};

const getPublicKey = (aPrivateKey: string): string => {
    return ec.keyFromPrivate(aPrivateKey, 'hex').getPublic().encode('hex');
};

const isValidTxOutStructure = (txOut: TxOut): boolean => {
    if (txOut == null) {
        console.log('txOut is null');
        return false;
    } else if (typeof txOut.address !== 'string') {
        console.log('invalid address type in txOut');
        return false;
    } else if (!isValidAddress(txOut.address)) {
        console.log('invalid TxOut address');
        return false;
    } else if (typeof txOut.amount !== 'number') {
        console.log('invalid amount type in txOut');
        return false;
    } else {
        return true;
    }
};

// valid address is a valid ecdsa public key in the 04 + X-coordinate + Y-coordinate format
const isValidAddress = (address: string): boolean => {
    if (address.length !== 130) {
        console.log(address);
        console.log('invalid public key length');
        return false;
    } else if (address.match('^[a-fA-F0-9]+$') === null) {
        console.log('public key must contain only hex characters');
        return false;
    } else if (!address.startsWith('04')) {
        console.log('public key must start with 04');
        return false;
    }
    return true;
};
     */
}
?>