<?php
trait tTransaction
{
    private function getTransactionId(cTransaction $oTransaction): string
    {
        $sTxInContent = join(array_map(function(cTxIn $oTxIns) { return $oTxIns->txOutId.$oTxIns->txOutIndex; }, $oTransaction->txIns));
        $sTxOutContent = join(array_map(function(cTxOut $oTxOuts) { return $oTxOuts->address.$oTxOuts->amount; }, $oTransaction->txOuts));
        
        return hash('sha256', (string)$sTxInContent.(string)$sTxOutContent);
    }
    
    private function validateTransaction(cTransaction $oTransaction, array $aUnspentTxOut) : bool
    {
        if(!$this->isValidTransactionStructure($oTransaction))
        {
            self::debug("Transaction structure is invalid in tx: {$oTransaction->id}");
            return false;
        }
        
        if($this->getTransactionId($oTransaction) != $oTransaction->id)
        {
            self::debug("Invalid TX id: {$oTransaction->id}");
            return false;
        }
        
        $bHasValidTxIns = (bool)array_reduce(array_map(function(cTxIn $oTxIns) use ($oTransaction) { return $this->validateTxIn($oTxIns, $oTransaction); }, $oTransaction->txIns), function($a, $b) { return $a && $b; }, true);
        if(!$bHasValidTxIns)
        {
            self::debug("Some of the txIns are invalid in tx: {$oTransaction->id}");
            return false;
        }
        
        return true;
    }
    
    private function isValidTransactionStructure(cTransaction $oTransaction): bool
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
        return true;
    }
    
    private function isValidTxInStructure(cTxIn $oTxIn): bool
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
    
    private function validateTxIn(cTxIn $oTxIn, cTransaction $oTransaction): bool
    {
        $sAddress           = $oTxIn->signature->address;
        $oKey               = $this->cEC->keyFromPublic($sAddress, 'hex');
        $bValidSignature    = $oKey->verify($oTransaction->id, $oTxIn->signature);
        if(!$bValidSignature)
        {
            self::debug("invalid txIn signature: {$oTxIn->signature} address {$sAddress}");
            return false;
        }
        return true;
    }
    
    private function getTxAmount(cTxIn $oTxIn, array $aUnspentTxOut): int
    {
        return $this->findUnspentTxOut($oTxIn->txOutId, $oTxIn->txOutIndex, $aUnspentTxOut)->amount;
    }
    
    private function findUnspentTxOut(string $sTransactionId, int $iIndex, array $aUnspentTxOut): cUnspentTxOut
    {
        return array_values(array_filter($aUnspentTxOut, function($oUnspentTxOut) use($sTransactionId, $iIndex) { return ($oUnspentTxOut->txOutId === $sTransactionId && $oUnspentTxOut->txOutIndex === $iIndex); }))[0];
    }
    
    private function getPublicKey(string $sPrivateKey): string
    {
        // ec.keyFromPrivate(aPrivateKey, 'hex').getPublic().encode('hex');
        return $this->cEC->keyFromPrivate($sPrivateKey, 'hex')->getPublic(false, 'hex');
    }
    
    private function signTxIn(cTransaction $oTransaction, int $iTxInIndex, string $sPrivateKey, array $aUnspentTxOut)
    {
        $oTxIn = $oTransaction->txIns[$iTxInIndex];
        
        $sDataToSign = $oTransaction->id;
        $oReferencedUnspentTxOut = $this->findUnspentTxOut($oTxIn->txOutId, $oTxIn->txOutIndex, $aUnspentTxOut);
        if($oReferencedUnspentTxOut == null)
        {
            self::debug("could not find referenced txOut");
        }
        $sReferencedAddress = $oReferencedUnspentTxOut->address;
        
        if($this->getPublicKey($sPrivateKey) !== $sReferencedAddress)
        {
            self::debug("trying to sign an input with private key that does not match the address that is referenced in txIn");
        }
        
        $sKey = $this->cEC->keyFromPrivate($sPrivateKey, 'hex');
        $sSignature = $this->cEC->sign($sDataToSign)->toDER('hex');
        
        return $sSignature;
    }
    
    public function sendTransaction(string $sReceiveAddress, stdClass $oDataObject)
    {
        $oTx = $this->createTransaction($sReceiveAddress, $oDataObject, $this->getPrivateFromWallet(), $this->getTransactionPool());
        
        // TODO addToTransactionPool(tx, getUnspentTxOuts());
        // TODO broadCastTransactionPool();
        
        return $oTx;
    }
    
    private function createTransaction(string $sReceiveAddress, stdClass $oDataObject, string $sPrivateKey, array $aTxPool): cTransaction
    {
        $sMyAddress = $this->getPublicKey($sPrivateKey);
        
        $cTxIn = new cTxIn();
        $cTxIn->txOutId = unspentTxOut.txOutId;
        $cTxIn->txOutIndex = unspentTxOut.txOutIndex;
        return $cTxIn;
        /*
         * const createTransaction = (receiverAddress: string, amount: number, privateKey: string,
         unspentTxOuts: UnspentTxOut[], txPool: Transaction[]): Transaction => {
         
         console.log('txPool: %s', JSON.stringify(txPool));
         const myAddress: string = getPublicKey(privateKey);
         const myUnspentTxOutsA = unspentTxOuts.filter((uTxO: UnspentTxOut) => uTxO.address === myAddress);
         
         const myUnspentTxOuts = filterTxPoolTxs(myUnspentTxOutsA, txPool);
         
         // filter from unspentOutputs such inputs that are referenced in pool
         const {includedUnspentTxOuts, leftOverAmount} = findTxOutsForAmount(amount, myUnspentTxOuts);
         
         const toUnsignedTxIn = (unspentTxOut: UnspentTxOut) => {
         const txIn: TxIn = new TxIn();
         txIn.txOutId = unspentTxOut.txOutId;
         txIn.txOutIndex = unspentTxOut.txOutIndex;
         return txIn;
         };
         
         const unsignedTxIns: TxIn[] = includedUnspentTxOuts.map(toUnsignedTxIn);
         
         const tx: Transaction = new Transaction();
         tx.txIns = unsignedTxIns;
         tx.txOuts = createTxOuts(receiverAddress, myAddress, amount, leftOverAmount);
         tx.id = getTransactionId(tx);
         
         tx.txIns = tx.txIns.map((txIn: TxIn, index: number) => {
         txIn.signature = signTxIn(tx, index, privateKey, unspentTxOuts);
         return txIn;
         });
         
         return tx;
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