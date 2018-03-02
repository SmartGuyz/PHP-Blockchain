<?php
abstract class cP2PServer
{
    use tUtils;
    
    abstract function getLastBlock();
    abstract function getBlockchain();
    abstract function isValidBlockStructure(cBlock $oBlock);
    abstract function addBlockToChain(cBlock $oNewBlock);
    abstract function replaceChain(array $aNewBlocks);
    abstract function getAdjustedDifficulty(cBlock $oLastBlock, array $aBlockchain);
    abstract function getTransactionPool(): array;
    
    const 
        QUERY_LATEST = 0,
        QUERY_ALL = 1,
        RESPONSE_BLOCKCHAIN = 2,
        QUERY_TRANSACTION_POOL = 3,
        RESPONSE_TRANSACTION_POOL = 4;
    
    public $aClientsInfo;
    
    public function responseLatestMsg()
    {
        $oResponse = new stdClass();
        $oResponse->type = self::RESPONSE_BLOCKCHAIN;
        $oResponse->data = serialize([$this->getLastBlock()]);
        
        return $oResponse;
    }
    
    public function responseChainMsg()
    {
        $oResponse = new stdClass();
        $oResponse->type = self::RESPONSE_BLOCKCHAIN;
        $oResponse->data = serialize($this->getBlockchain());
        
        return $oResponse;
    }
    
    public function broadcastLatest()
    {
        $this->broadcast($this->responseLatestMsg());
    }
    
    public function queryChainLengthMsg()
    {
        $oResponse = new stdClass();
        $oResponse->type = self::QUERY_LATEST;
        $oResponse->data = null;
        
        return $oResponse;
    }
    
    public function queryAllMsg()
    {
        $oResponse = new stdClass();
        $oResponse->type = self::QUERY_ALL;
        $oResponse->data = null;
        
        return $oResponse;
    }
    
    public function queryTransactionPoolMsg()
    {
        $oResponse = new stdClass();
        $oResponse->type = self::QUERY_TRANSACTION_POOL;
        $oResponse->data = null;
        
        return $oResponse;
    }
    
    public function responseTransactionPoolMsg()
    {
        $oResponse = new stdClass();
        $oResponse->type = self::RESPONSE_TRANSACTION_POOL;
        $oResponse->data = serialize($this->getTransactionPool());
        
        return $oResponse;
    }
    
    public function broadcast(stdClass $oData)
    {
        foreach($this->aClientsInfo AS $iKey => $aPeer)
        {
            if($aPeer['protocol'] == 'p2p')
            {
                $sData = serialize($oData);
                socket_send($aPeer['resource'], $sData, strlen($sData), MSG_EOF);
            }
        }
    }
    
    public function broadCastTransactionPool()
    {
        $this->broadcast($this->responseTransactionPoolMsg());
    }
    
    public function handleBlockchainResponse(array $aReceivedBlocks)
    {
        if(count($aReceivedBlocks) == 0)
        {
            self::debug("handleBlockchainResponse() -> received blockchain size of 0");
            return;
        }
        
        $oLatestBlockReceived = $aReceivedBlocks[count($aReceivedBlocks) - 1];
        if(!$this->isValidBlockStructure($oLatestBlockReceived))
        {
            self::debug("handleBlockchainResponse() -> block structuture not valid");
            return;
        }
        
        $oLatestBlockHeld = $this->getLastBlock();
        if($oLatestBlockReceived->index > $oLatestBlockHeld->index)     // is blockchain behind?
        {
            if($oLatestBlockHeld->hash === $oLatestBlockReceived->prevHash)
            {
                if($this->addBlockToChain($oLatestBlockReceived))
                {
                    self::debug("handleBlockchainResponse() -> broadcastLatest()");
                    $this->broadcastLatest();
                }
            }
            elseif(count($aReceivedBlocks) === 1)
            {
                // We have to query the chain from our peer
                self::debug("handleBlockchainResponse() -> queryAllMsg()");
                $this->broadcast($this->queryAllMsg());
            }
            else
            {
                // Received blockchain is longer than current blockchain
                self::debug("handleBlockchainResponse() -> replaceChain()");
                $this->replaceChain($aReceivedBlocks);
            }
        }
        else
        {
            self::debug("handleBlockchainResponse() -> received blockchain is not longer than own blockchain. Do nothing");
        }
    }
}
?>