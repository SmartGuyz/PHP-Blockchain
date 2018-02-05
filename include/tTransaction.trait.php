<?php
trait tTransaction
{
    use tTransactionPool;
    
    private function getTransactionId(cTransaction $oTransaction): string
    {
        $sTxInContent = join(array_map(function(cTxIn $oTxIns) { return $oTxIns->fromAddress.$oTxIns->toAddress.$oTxIns->time.serialize($oTxIns->dataObject); }, $oTransaction->txIns));
        
        return hash('sha256', (string)$sTxInContent);
    }
    
    private function validateTransaction(cTransaction $oTransaction) : bool
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
        elseif(gettype($oTxIn->time) !== "integer")
        {
            return false;
        }
        elseif(gettype($oTxIn->toAddress) !== "string")
        {
            return false;
        }
        elseif(gettype($oTxIn->fromAddress) !== "string")
        {
            return false;
        }
        elseif(gettype($oTxIn->dataObject) !== "object")
        {
            return false;
        }
        return true;
    }
    
    private function validateTxIn(cTxIn $oTxIn, cTransaction $oTransaction): bool
    {
        $sFromAddress       = $oTxIn->fromAddress;
        $oKey               = $this->cEC->keyFromPublic($sFromAddress, 'hex');
        $bValidSignature    = $oKey->verify($oTransaction->id, $oTxIn->signature);
        if(!$bValidSignature)
        {
            self::debug("invalid txIn signature: {$oTxIn->signature} address {$sFromAddress}");
            return false;
        }
        return true;
    }
    
    private function getPublicKey(string $sPrivateKey): string
    {
        return $this->cEC->keyFromPrivate($sPrivateKey, 'hex')->getPublic(false, 'hex');
    }
    
    private function signTxIn(string $sTxIn, string $sPrivateKey)
    {
        $oKey = $this->cEC->keyFromPrivate($sPrivateKey, 'hex');
        $sSignature = $oKey->sign($sTxIn)->toDER('hex');
        
        return $sSignature;
    }
    
    public function sendTransaction(string $sReceiveAddress, stdClass $oDataObject)
    {
        $oTx = $this->createTransaction($sReceiveAddress, $oDataObject, $this->getPrivateFromWallet(), $this->getTransactionPool());
        $this->addToTransactionPool($oTx);
        $this->broadCastTransactionPool();
        
        return $oTx;
    }
    
    private function createTransaction(string $sReceiveAddress, stdClass $oDataObject, string $sPrivateKey, array $aTxPool): cTransaction
    {
        $sMyAddress = $this->getPublicKey($sPrivateKey);
        
        $cTxIn = new cTxIn();
        $cTxIn->fromAddress = $this->getPublicFromWallet();
        $cTxIn->toAddress = $sReceiveAddress;
        $cTxIn->dataObject = $oDataObject;
        $cTxIn->time = time();
        
        $cTransaction = new cTransaction();
        $cTransaction->txIns[] = $cTxIn;   
        $cTransaction->id = $sTxID = $this->getTransactionId($cTransaction);
        $cTransaction->txIns = array_map(function(cTxIn $oTxIns) use($sPrivateKey, $sTxID) { $oTxIns->signature = $this->signTxIn($sTxID, $sPrivateKey); return $oTxIns; }, $cTransaction->txIns);

        return $cTransaction;
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