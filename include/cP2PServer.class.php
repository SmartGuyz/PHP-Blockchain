<?php
abstract class cP2PServer
{
    abstract function getLastBlock();
    abstract function getBlockchain();
    abstract function isValidBlockStructure(cBlock $oBlock);
    abstract function addBlockToChain(cBlock $oNewBlock);
    abstract function replaceChain(array $aNewBlocks);
    
    const 
        QUERY_LATEST = 0,
        QUERY_ALL = 1,
        RESPONSE_BLOCKCHAIN = 2,
        QUERY_TRANSACTION_POOL = 3,
        RESPONSE_TRANSACTION_POOL = 4;
    
    public $aPeers;
    
    public function responseLatestMsg()
    {
        $oResponse = new stdClass();
        $oResponse->type = self::RESPONSE_BLOCKCHAIN;
        $oResponse->data = [serialize($this->getLastBlock())];
        
        return $oResponse;
    }
    
    public function responseChainMsg()
    {
        $oResponse = new stdClass();
        $oResponse->type = self::RESPONSE_BLOCKCHAIN;
        $oResponse->data = json_encode($this->getBlockchain());
        
        return $oResponse;
    }
    
    public function broadcastLatest()
    {
        $this->broadcast(responseLatestMsg());
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
    
    
    public function broadcast(stdClass $oData)
    {
        foreach($this->aPeers AS $iKey => $aPeer)
        {
            socket_send($aPeer['resource'], $oData, strlen($oData), MSG_EOF);
        }
    }
    
    public function handleBlockchainResponse(array $aReceivedBlocks)
    {
        if(count($aReceivedBlocks) == 0)
        {
            return;
        }
        
        $oLatestBlockReceived = $aReceivedBlocks[count($aReceivedBlocks) - 1];
        if(!$this->isValidBlockStructure($oLatestBlockReceived))
        {
            return;
        }
        
        $oLatestBlockHeld = $this->getLastBlock();
        if($oLatestBlockReceived->index > $oLatestBlockHeld->index)     // is blockchain behind?
        {
            if($oLatestBlockHeld->hash === $oLatestBlockReceived->prevHash)
            {
                if($this->addBlockToChain($oLatestBlockReceived))
                {
                    $this->broadcastLatest();
                }
            }
            elseif(count($aReceivedBlocks) === 1)
            {
                // We have to query the chain from our peer
                $this->broadcast(queryAllMsg());
            }
            else
            {
                // Received blockchain is longer than current blockchain
                $this->replaceChain($aReceivedBlocks);
            }
        }
        else
        {
            //received blockchain is not longer than received blockchain. Do nothing
            return;
        }
    }
}
?>