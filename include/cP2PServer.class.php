<?php
abstract class cP2PServer
{
    abstract function getLastBlock();
    
    const 
        QUERY_LATEST = 0,
        QUERY_ALL = 1,
        RESPONSE_BLOCKCHAIN = 2,
        QUERY_TRANSACTION_POOL = 3,
        RESPONSE_TRANSACTION_POOL = 4;
    
    private function responseLatestMsg()
    {
        $aResponse['type'] = self::RESPONSE_BLOCKCHAIN;
        $aResponse['data'] = $this->getLastBlock();
    }
    
    private function queryTransactionPoolMsg()
    {
        
    }
    
    private function queryChainLengthMsg()
    {
        
    }
    
    private function queryAllMsg()
    {
        
    }
    
    private function responseTransactionPoolMsg()
    {
        
    }
    
    private function broadcastLatest()
    {
        
    }
    
    private function broadCastTransactionPool()
    {
        
    }
    
    private function handleBlockchainResponse()
    {
        
    }
}
?>