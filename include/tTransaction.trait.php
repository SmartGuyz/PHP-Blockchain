<?php
use Underscore\Underscore as _;

trait tTransaction
{
    use tTransactionPool;
    
    private function getTransactionId(cTransaction $oTransaction): string
    {
        $sTxInContent = array_reduce(array_map(function(cTxIn $oTxIns) { return $oTxIns->txOutId.$oTxIns->txOutIndex; }, $oTransaction->txIns), function($a, $b) { return $a.$b; }, '');
        $sTxOutContent = array_reduce(array_map(function(cTxOut $oTxOuts) { return $oTxOuts->address.$oTxOuts->amount; }, $oTransaction->txOuts), function($a, $b) { return $a.$b; }, '');
        
        return hash('sha256', (string)$sTxInContent.(string)$sTxOutContent);
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
        
        // Validate receive address
        $this->isValidAddress($sReceiveAddress);
        
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
    
    private function isValidAddress(string $sAddress): void
    {
        if(strlen($sAddress) !== 130)
        {
            throw new Exception("invalid public key length: {$sAddress}");
        }
        elseif(preg_match('/^[a-fA-F0-9]+$/', $sAddress) !== 1)
        {
            throw new Exception("public key must contain only hex characters: {$sAddress}");
        }
        elseif(strpos($sAddress, '04') !== 0)
        {
            throw new Exception("public key must start with 04: {$sAddress}");
        }
    }
    
    private function processTransactions(array $aTransactions, array $aUnspentTxOuts, int $iBlockIndex)
    {
        if(!$this->validateBlockTransactions($aTransactions, $aUnspentTxOuts, $iBlockIndex))
        {
            self::debug("invalid block transactions");
            return null;
        }
        
        return $this->updateUnspentTxOuts($aTransactions, $aUnspentTxOuts);
    }
    
    private function updateUnspentTxOuts(array $aTransactions, array $aUnspentTxOuts): array
    {       
        $aNewUnspentTxOuts = array_reduce(array_map(function(cTransaction $oTransaction) { return array_map(function(cTxOut $oTxOut, $iKey) use($oTransaction) { return new cUnspentTxOut($oTransaction->id, $iKey, $oTxOut->address, $oTxOut->amount); }, $oTransaction->txOuts, array_keys($oTransaction->txOuts)); }, $aTransactions), function($a, $b) { return array_merge($a, $b); }, []);        
        $aConsumedTxOuts = array_map(function(cTxIn $oTxIn) { return new cUnspentTxOut($oTxIn->txOutId, $oTxIn->txOutIndex, '', 0); }, array_reduce(array_map(function(cTransaction $oTransaction) { return $oTransaction->txIns; }, $aTransactions), function($a, $b) { return array_merge($a, $b); }, []));
        $aResultingUnspentTxOuts = array_merge(array_filter($aUnspentTxOuts, function($oUnspentTxOut) use($aConsumedTxOuts) { return !$this->findUnspentTxOut($oUnspentTxOut->txOutId, $oUnspentTxOut->txOutIndex, $aConsumedTxOuts); }), $aNewUnspentTxOuts);
        
        return $aResultingUnspentTxOuts;
    }
    
    private function validateBlockTransactions(array $aTransactions, array $aUnspentTxOuts, int $iBlockIndex)
    {
        $oCoinBaseTx = $aTransactions[0];
        if(!$this->validateCoinbaseTx($oCoinBaseTx, $iBlockIndex))
        {
            self::debug("Invalid coinbase transaction: ".json_encode($oCoinBaseTx));
            return false;
        }
        
        // check for duplicate txIns. Each txIn can be included only once
        $aTxIns = [];
        $aTxMap = array_map(function(cTransaction $oTransaction) { return $oTransaction->txIns; }, $aTransactions);
        array_walk_recursive($aTxMap, function($v, $k) use(&$aTxIns) { $aTxIns[] = $v; });
        
        if($this->hasDuplicates($aTxIns))
        {
            return false;
        }
        
        // all but coinbase transactions
        $aTransactions = array_slice($aTransactions, 1);
        return array_reduce(array_map(function(cTransaction $oTransaction) use ($aUnspentTxOuts) { return $this->validateTransaction($oTransaction, $aUnspentTxOuts); }, $aTransactions), function($a, $b) { return $a && $b; }, true);
    }
    
    private function hasDuplicates(array $aTxIns)
    {
        $aGroups = _::countBY($aTxIns, function($oTxIn) { return $oTxIn->txOutId.$oTxIn->txOutIndex; });
        array_walk($aGroups, function(&$a, $b) { $a = (($a > 1) ? true : false); });
        return (bool)array_reduce($aGroups, function($a, $b) { return $a && $b; }, false);
    }
    
    private function validateCoinbaseTx(cTransaction $oTransaction, int $iBlockIndex): bool
    {
        if($oTransaction == null)
        {
            self::debug("the first transaction in the block must be coinbase transaction");
            return false;
        }
        
        if($this->getTransactionId($oTransaction) != $oTransaction->id)
        {
            self::debug("invalid coinbase tx id: {$oTransaction->id} vs {$this->getTransactionId($oTransaction)}");
            return false;
        }
        
        if(count($oTransaction->txIns) !== 1)
        {
            self::debug("one txIn must be specified in the coinbase transaction");
            return false;
        }
        
        if($oTransaction->txIns[0]->txOutIndex !== $iBlockIndex)
        {
            self::debug("the txIn signature in coinbase tx must be the block height");
            return false;
        }
        
        if(count($oTransaction->txOuts) !== 1)
        {
            self::debug("invalid number of txOuts in coinbase transaction");
            return false;
        }
        
        if($oTransaction->txOuts[0]->amount !== self::COINBASE_AMOUNT)
        {
            self::debug("invalid coinbase amount in coinbase transaction");
            return false;
        }
        
        return true;
    }
    
    private function validateTransaction(cTransaction $oTransaction, array $aUnspentTxOuts): bool
    {
        if(!$this->isValidTransactionStructure($oTransaction))
        {
            return false;
        }
        
        if($this->getTransactionId($oTransaction) != $oTransaction->id)
        {
            self::debug("invalid tx id: {$oTransaction->id} vs {$this->getTransactionId($oTransaction)}");
            return false;
        }
        
        $bHasValidTxIns = (bool)array_reduce(array_map(function(cTxIn $oTxIn) use ($oTransaction) { return $this->validateTxIn($oTxIn, $oTransaction); }, $oTransaction->txIns), function($a, $b) { return $a && $b; }, true);
        if(!$bHasValidTxIns)
        {
            self::debug("Some of the txIns are invalid in tx: {$oTransaction->id}");
            return false;
        }
        
        $iTotalTxInValues = array_reduce(array_map(function(cTxIn $oTxIn) use ($aUnspentTxOuts) { return $this->getTxInAmount($oTxIn, $aUnspentTxOuts); }, $oTransaction->txIns), function($a, $b) { return $a + $b; }, 0);
        $iTotalTxOutValues = array_reduce(array_map(function(cTxOut $oTxOut) { return $oTxOut->amount; }, $oTransaction->txOuts), function($a, $b) { return $a + $b; }, 0);
        
        if($iTotalTxOutValues !== $iTotalTxInValues)
        {
            self::debug("totalTxOutValues !== totalTxInValues in tx: {$oTransaction->id}");
            return false;
        }
        
        return true;
    }
    
    private function getTxInAmount(cTxIn $oTxIn, array $aUnspentTxOuts): int
    {
        return $this->findUnspentTxOut($oTxIn->txOutId, $oTxIn->txOutIndex, $aUnspentTxOuts)->amount;
    }
    
    private function findUnspentTxOut(string $sTxOutId, int $iTxOutIndex, array $aUnspentTxOuts)
    {
        return array_map(function(cUnspentTxOut $oUTxO) use ($sTxOutId, $iTxOutIndex) { return ($oUTxO->txOutId === $sTxOutId && $oUTxO->txOutIndex === $iTxOutIndex); }, $aUnspentTxOuts);
    }
    
    private function isValidTransactionStructure(cTransaction $oTransaction): bool
    {
        if(gettype($oTransaction->id) !== 'string')
        {
            self::debug("transactionId missing");
            return false;
        }
        
        if(gettype($oTransaction->txIns) !== 'array')
        {
            self::debug("invalid txIns type in transaction");
            return false;
        }
        
        if(!array_reduce(array_map([$this, 'isValidTxInStructure'], $oTransaction->txIns), function($a, $b) { return $a && $b; }, true))
        {
            return false;
        }
        
        if(gettype($oTransaction->txOuts) !== 'array')
        {
            self::debug("invalid txOuts type in transaction");
            return false;
        }
        
        if(!array_reduce(array_map([$this, 'isValidTxOutStructure'], $oTransaction->txOuts), function($a, $b) { return $a && $b; }, true))
        {
            return false;
        }
        
        return true;
    }
    
    private function isValidTxInStructure(cTxIn $oTxIn): bool
    {
        if($oTxIn == null)
        {
            self::debug("txIn is null");
            return false;
        }
        elseif(gettype($oTxIn->signature) !== 'string')
        {
            self::debug("invalid signature type in txIn");
            return false;
        }
        elseif(gettype($oTxIn->txOutId) !== 'string')
        {
            self::debug("invalid txOutId type in txIn");
            return false;
        }
        elseif(gettype($oTxIn->txOutIndex) !== 'integer')
        {
            self::debug("invalid txOutIndex type in txIn");
            return false;
        }
        return true;
    }
    
    private function isValidTxOutStructure(cTxOut $oTxOut)
    {
        if($oTxOut == null)
        {
            self::debug("txIn is null");
            return false;
        }
        elseif(gettype($oTxOut->address) !== 'string')
        {
            self::debug("invalid address type in txOu");
            return false;
        }
        elseif($this->isValidAddress($oTxOut->address))
        {
            self::debug("invalid TxOut address");
            return false;
        }
        elseif(gettype($oTxOut->amount) !== 'integer')
        {
            self::debug("invalid amount type in txOut");
            return false;
        }
        return true;
    }
}
?>