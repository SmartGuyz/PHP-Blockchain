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
    
    private function isValidTxForPool(cTransaction $oTransaction, array $aTransactionPool)
    {
        return (bool)array_reduce(array_map(function(cTransaction $oTransactionPool) use ($oTransaction) { return (($oTransactionPool->id == $oTransaction->id) ? false : true); }, $aTransactionPool), function($a, $b) { return $a && $b; }, true);
    }
    
    /*let transactionPool: Transaction[] = [];

    const hasTxIn = (txIn: TxIn, unspentTxOuts: UnspentTxOut[]): boolean => {
        const foundTxIn = unspentTxOuts.find((uTxO: UnspentTxOut) => {
            return uTxO.txOutId === txIn.txOutId && uTxO.txOutIndex === txIn.txOutIndex;
        });
        return foundTxIn !== undefined;
    };
    
    const updateTransactionPool = (unspentTxOuts: UnspentTxOut[]) => {
        const invalidTxs = [];
        for (const tx of transactionPool) {
            for (const txIn of tx.txIns) {
                if (!hasTxIn(txIn, unspentTxOuts)) {
                    invalidTxs.push(tx);
                    break;
                }
            }
        }
        if (invalidTxs.length > 0) {
            console.log('removing the following transactions from txPool: %s', JSON.stringify(invalidTxs));
            transactionPool = _.without(transactionPool, ...invalidTxs);
        }
    };
    
    const getTxPoolIns = (aTransactionPool: Transaction[]): TxIn[] => {
        return _(aTransactionPool)
            .map((tx) => tx.txIns)
            .flatten()
            .value();
    };
    
    };*/
}
?>