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
        
        $bHasValidTxIns = (bool)array_reduce(array_map(function(cTxIn $oTxIns) use ($oTransaction, $aUnspentTxOut) { return $this->validateTxIn($oTxIns, $oTransaction, $aUnspentTxOut); }, $oTransaction->txIns), function($a, $b) { return $a && $b; }, true);
        if(!$bHasValidTxIns)
        {
            self::debug("Some of the txIns are invalid in tx: {$oTransaction->id}");
            return false;
        }
        
        $iToalTxInValues = (int)array_reduce(array_map(function(cTxIn $oTxIns) use ($aUnspentTxOut) { return $this->getTxInAmount($oTxIns, $aUnspentTxOut); }, $oTransaction->txIns), function($a, $b) { return ($a + $b); }, 0);
        $iToalTxOutValues = (int)array_reduce(array_map(function(cTxOut $oTxOuts) { return $oTxOuts->amount; }, $oTransaction->txOuts), function($a, $b) { return ($a + $b); }, 0);
        if($iToalTxOutValues !== $iToalTxInValues)
        {
            self::debug("totalTxOutValues !== totalTxInValues in tx: {$oTransaction->id}");
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
        elseif(!array_reduce(array_map([$this, 'isValidTxInStructure'], $oTransaction->txOuts), function($a, $b) { return $a && $b; }, true))
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
    
    private function validateTxIn(cTxIn $oTxIn, cTransaction $oTransaction, array $aUnspentTxOut): bool
    {
        $oReferencedUTxOut = array_values(array_filter($aUnspentTxOut, function($oUnspentTxOut) use($oTxIn) { return ($oUnspentTxOut->txOutId === $oTxIn->txOutId && $oUnspentTxOut->txOutIndex === $oTxIn->txOutIndex); }))[0];
        if($oReferencedUTxOut == null)
        {
            self::debug("referenced txOut not found: ".json_encode($oTxIn));
            return false;
        }
        
        $sAddress           = $oReferencedUTxOut->address;
        $sKey               = $this->cEC->keyFromPublic($sAddress, 'hex');
        $bValidSignature    = $this->cEC->verify($oTransaction->id, $oTxIn->signature);
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