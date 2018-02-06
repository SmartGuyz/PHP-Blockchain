<?php
trait tTransactionPool
{
    private $aTransactionPool = [];
    
    private function addToTransactionPool(cTransaction $oTransaction): void
    {
        if(!$this->validateTransaction($oTransaction))
        {
            throw new Exception('Trying to add invalid tx to pool');
        }
        
        if(!$this->isValidTxForPool($oTransaction, $this->aTransactionPool))
        {
            throw new Exception('Trying to add invalid tx to pool');
        }
        
        array_push($this->aTransactionPool, $oTransaction);
    }
    
    public function handleReceivedTransaction(cTransaction $oTransaction): void
    {
        $this->addToTransactionPool($oTransaction);
    }
    
    public function getTransactionPool()
    {
        $oArrayObject = new ArrayObject($this->aTransactionPool);
        return $oArrayObject->getArrayCopy();
    }
    
    public function replaceTransactionPool(array $aTransactionPool)
    {
        if(gettype($aTransactionPool) === "array")
        {
            $this->aTransactionPool = $aTransactionPool;
        }
    }
    
    private function isValidTxForPool(cTransaction $oTransaction, array $aTransactionPool)
    {
        return (bool)array_reduce(array_map(function(cTransaction $oTransactionPool) use ($oTransaction) { return (($oTransactionPool->id == $oTransaction->id) ? false : true); }, $aTransactionPool), function($a, $b) { return $a && $b; }, true);
    }
}
?>