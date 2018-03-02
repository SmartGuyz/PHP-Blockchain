<?php
trait tTransactionPool
{
    private function addToTransactionPool(cTransaction $oTx, array $aUnspentTxOut): void
    {
        if(!$this->validateTransaction($oTx, $aUnspentTxOut))
        {
            throw new Exception('Trying to add invalid tx to pool');
        }
        
        if(!$this->isValidTxForPool($oTx, $this->aTransactionPool))
        {
            throw new Exception('Trying to add invalid tx to pool');
        }
        
        array_push($this->aTransactionPool, $oTx);
    }
    
    public function handleReceivedTransaction(cTransaction $oTransaction): void
    {
        $this->addToTransactionPool($oTransaction, $this->getUnspentTxOuts());
    }
    
    public function getTransactionPool(): array
    {
        $oArrayObject = new ArrayObject($this->aTransactionPool);
        return $oArrayObject->getArrayCopy();
    }
    
    public function getUnspentTxOuts(): array
    {
        $oArrayObject = new ArrayObject($this->aUnspentTxOuts);
        return $oArrayObject->getArrayCopy();
    }
    
    public function replaceTransactionPool(array $aTransactionPool)
    {
        if(gettype($aTransactionPool) === "array")
        {
            self::debug("Replacing transactionPool");
            $this->aTransactionPool = $aTransactionPool;
        }
    }
    
    public function getMyUnspentTransactionOutputs()
    {
        return $this->findUnspentTxOuts($this->getPublicFromWallet(), $this->getUnspentTxOuts());
    }
    
    public function setUnspentTxOuts(array $aNewUnspentTxOut): void
    {
        if(gettype($aNewUnspentTxOut) === "array")
        {
            self::debug("Replacing unspentTxouts");
            $this->aUnspentTxOuts = $aNewUnspentTxOut;
        }
    }
    
    private function isValidTxForPool(cTransaction $oTransaction, array $aTransactionPool)
    {
        return (bool)array_reduce(array_map(function(cTransaction $oTransactionPool) use ($oTransaction) { return (($oTransactionPool->id == $oTransaction->id) ? false : true); }, $aTransactionPool), function($a, $b) { return $a && $b; }, true);
    }
}
?>