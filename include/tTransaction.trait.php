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
        elseif(preg_match('^[a-fA-F0-9]+$', $sAddress) !== 1)
        {
            throw new Exception("public key must contain only hex characters: {$sAddress}");
        }
        elseif(strpos($sAddress, '04') !== 0)
        {
            throw new Exception("public key must start with 04: {$sAddress}");
        }
    }
}
?>
?>