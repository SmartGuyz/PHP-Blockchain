<?php
use Underscore\Underscore as _;

trait tTransactionPool
{
	/**
	 * @throws Exception
	 */
	private function addToTransactionPool(cTransaction $oTx, array $aUnspentTxOut): void
    {
        if(!$this->validateTransaction($oTx, $aUnspentTxOut))
        {
            throw new Exception('Trying to add invalid tx to pool (validateTransaction)');
        }
        
        if(!$this->isValidTxForPool($oTx, $this->aTransactionPool))
        {
            throw new Exception('txIn already found in the txPool');
        }
        
        self::debug("adding to txPool: ".json_encode($oTx));
        $this->aTransactionPool[] = $oTx;
    }
    
    private function hasTxIn(cTxIn $oTxIn, array $aUnspentTxOuts): bool
    {
        $oFoundTxIn = _::find($aUnspentTxOuts, function(cUnspentTxOut $oUTxO) use ($oTxIn) { return ($oUTxO->txOutId === $oTxIn->txOutId && $oUTxO->txOutIndex === $oTxIn->txOutIndex); });
        return ($oFoundTxIn !== null);
    }

	/**
	 * @throws Exception
	 */
	private function updateTransactionPool(array $aUnspentTxOuts): void
	{
        $aInvalidTxs = [];
        foreach($this->aTransactionPool AS $oTx)
        {
            foreach($oTx->txIns AS $oTxIn)
            {
                if(!$this->hasTxIn($oTxIn, $aUnspentTxOuts))
                {
                    $aInvalidTxs[] = $oTx;
                    break;
                }
            }
        }
        
        if(count($aInvalidTxs) > 0)
        {
            self::debug("removing the following transactions from txPool: ".json_encode($aInvalidTxs));
            
            $compareObjects = function($obj_a, $obj_b) {
                return ($obj_a->id != $obj_b->id);
            };
            $this->aTransactionPool = array_udiff($this->aTransactionPool, $aInvalidTxs, $compareObjects);
        }
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

	/**
	 * @throws Exception
	 */
	public function replaceTransactionPool(array $aTransactionPool): void
	{
        if(gettype($aTransactionPool) === "array")
        {
            self::debug("Replacing transactionPool");
            $this->aTransactionPool = $aTransactionPool;
        }
    }

	/**
	 * @throws Exception
	 */
	public function setUnspentTxOuts(array $aNewUnspentTxOut): void
    {
        if(gettype($aNewUnspentTxOut) === "array")
        {
            self::debug("Replacing unspentTxouts");
            $this->aUnspentTxOuts = $aNewUnspentTxOut;
        }
    }
    
    private function isValidTxForPool(cTransaction $oTransaction, array $aTransactionPool): bool
	{
        return array_reduce(array_map(function(cTransaction $oTransactionPool) use ($oTransaction) { return !(($oTransactionPool->id == $oTransaction->id)); }, $aTransactionPool), function($a, $b) { return $a && $b; }, true);
    }
}