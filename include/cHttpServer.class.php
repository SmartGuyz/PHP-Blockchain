<?php
use Underscore\Underscore as _;

class cHttpServer
{
    use tSocketServer;
    
    protected array $aRead, $rMasterSocket;
    private int $iMaxClients, $iMaxRead;
	private array $aIniValues;

	/**
	 * @throws Exception
	 */
	public function __construct(array $aConfig)
    {
        $this->iMaxClients  = 1024;
        $this->iMaxRead     = 1024;
        
        $this->aIniValues   = $aConfig;
        
        $this->createSocket($this->aIniValues);
    }

	/**
	 * @throws Exception
	 */
	public function run(cBlockchain $oBlockchain): void
	{
        $cBlockchain = $oBlockchain;
        
        foreach($this->aIniValues AS $i => $aValue)
        {
            socket_getsockname($this->rMasterSocket[$i], $sServerIP, $sServerPort);
            
            $cBlockchain->aClientsInfo[] = ['resource' => $this->rMasterSocket[$i], 'ipaddr' => $sServerIP, 'port' => $sServerPort, 'protocol' => 'master'];
        }
        
        while(true)
        {
            // Reset array keys for client array
			$cBlockchain->aClientsInfo = array_values($cBlockchain->aClientsInfo);
			$this->aRead               = array_column($cBlockchain->aClientsInfo, 'resource');
            
            // Zet blocking via socket_select
            $sNull = null;
            if(@socket_select($this->aRead, $sNull, $sNull, $sNull) < 1)
            {
                self::debug("Problem blocking socket_select?");
                continue;
            }
            
            // Handle nieuwe verbindingen
            foreach($this->aIniValues AS $i => $aValue)
            {
                if(in_array($this->rMasterSocket[$i], $this->aRead))
                {
                    if(($rMsgSocket = @socket_accept($this->rMasterSocket[$i])) === false)
                    {
                        self::debug("socket_accept() failed: reason: ".socket_strerror(socket_last_error($this->rMasterSocket[$i])));
                        break;
                    }
                    else
                    {
                        socket_set_option($rMsgSocket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
                        socket_set_option($rMsgSocket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);
                        
                        // Client info
                        socket_getpeername($rMsgSocket, $sClientIP, $sClientPort);
                        
                        self::debug("Incoming connection from {$sClientIP}:{$sClientPort} ({$aValue['name']})");

                        $cBlockchain->aClientsInfo[] = ['resource' => $rMsgSocket, 'ipaddr' => $sClientIP, 'port' => $sClientPort, 'protocol' => $aValue['name'], 'time' => time()];
                    }
                }
            }
            
            // Handle nieuwe input
            foreach($cBlockchain->aClientsInfo AS $iKey => $aClient)
            {
                if($aClient['protocol'] == 'master')
                {
                    continue;
                }
                
                if(in_array($aClient['resource'], $this->aRead))
                { 
                    // TODO : Needs a fix
                    $sBuffer = null;
                    if($aClient['protocol'] == 'p2p')
                    {
                        while(($iFlag = socket_recv($aClient['resource'], $sTempBuffer, 1024, 0)) > 0)
                        {
                            $sBuffer .= $sTempBuffer;
                        }
                        
                        if($iFlag < 0)
                        {
                            self::debug("socket_recv error, closing connection for client {$iKey}");
                            $this->closeConnection($iKey);
                            continue;
                        }
                        elseif($iFlag === 0)
                        {
                            self::debug("Buffer empty, closing connection for client {$iKey}");
                            $this->closeConnection($iKey);
                            continue;
                        }
                    }
                    elseif($aClient['protocol'] == 'http')
                    {
                        $iError = 0;
                        while(true) 
                        {
                            $iBytes = @socket_recv($aClient['resource'], $sTempBuffer, 1024, MSG_DONTWAIT);
                            
                            $iLastError = socket_last_error($aClient['resource']);
                            if($iLastError != 11 && $iLastError > 0)
                            {
                                self::debug("socket_recv error, closing connection for client {$iKey}");
                                $this->closeConnection($iKey);
                                $iError++;
                                break;
                            }
                            
                            if($iBytes === false)
                            {
                                break;
                            }
                            elseif($iBytes < 0)
                            {
                                self::debug("socket_recv error, losing connection for client {$iKey}");
                                $this->closeConnection($iKey);
                                $iError++;
                                break;
                            }
                            elseif($iBytes === 0)
                            {
                                self::debug("Buffer empty, closing connection for client {$iKey}");
                                $this->closeConnection($iKey);
                                $iError++;
                                break;
                            }
                            $sBuffer .= $sTempBuffer;
                        }
                        
                        if($iError > 0)
                        {
                            continue;
                        }
                    }

                    if(empty($sBuffer) OR $sBuffer == '')
                    {
                        self::debug("Closing connection for key {$iKey}");
                        $this->closeConnection($iKey);
                        continue;
                    }
                    
                    $sBuffer = trim($sBuffer); 
                    
                    //self::debug("Received {$sBuffer}");
                    
                    if($aClient['protocol'] == 'http')
                    {
                        // Make array of lines
                        $aIncoming = explode("\r\n", $sBuffer);
                        
                        // Extract body from headers
                        $aBody = explode("\r\n\r\n", $sBuffer);
                        $sBody = ((isset($aBody[1])) ? $aBody[1] : "");
                        if(@json_decode(trim($sBody)))
                        {
                            $oBody = json_decode(trim($sBody));
                        }
                        
                        // Get page and method
                        preg_match('~.+?(?=\sHTTP/1\.1)~is', $aIncoming[0], $aMatches);
                        [$sMethod, $sPage] = explode(" ", $aMatches[0]);
                        
                        // Explode arguments
                        $aArguments = explode("/", $sPage);
                        unset($aArguments[0]); // Always empty
                        
                        // check if we have any arguments (pages)
                        if(count($aArguments) > 0)
                        {
                            // POST or GET?
                            switch($sMethod)
                            {
                                case 'GET':
                                    switch($aArguments[1])
                                    {
                                        case 'blocks':      // Return all blocks of the chain
                                            $this->send($cBlockchain->getBlockchain(), $iKey);
                                            break;
                                        case 'block':       // Return only the requested block (hash)
                                            if(!isset($aArguments[2]))
                                            {
                                                $this->send('', $iKey, 400);
                                            }
                                            else 
                                            {
                                                $sHashToSearch = trim($aArguments[2]);
                                                $aBlock = _::find($cBlockchain->getBlockchain(), function(cBlock $oBlock) use ($sHashToSearch) { return ($oBlock->hash === $sHashToSearch); });
                                                $this->send((($aBlock === null) ? ['error' => 'Block hash found'] : $aBlock), $iKey);
                                            }
                                            break;
                                        case 'addressTransactions':
                                            if(!isset($aArguments[2]))
                                            {
                                                $this->send('', $iKey, 400);
                                            }
                                            else
                                            {
                                                $sAddress = $aArguments[2];
                                                
                                                $aTxs = [];
                                                $aReturn = [];
                                                
                                                $aTxMap = array_map(function(cBlock $oBlock) { return $oBlock->data; }, $cBlockchain->getBlockchain());
                                                array_walk_recursive($aTxMap, function($v) use(&$aTxs) { $aTxs[] = $v; });
                                                
                                                foreach($aTxs AS $oTransaction)
                                                {
                                                    foreach($oTransaction->txIns AS $oTxIn)
                                                    {
                                                        $oTx = _::find($aTxs, function($oTx) use($oTxIn) { return ($oTx->id == $oTxIn->txOutId); });
                                                        $aTemp = @array_filter($oTx->txOuts, function(cTxOut $oTxOut) use($sAddress) { return $oTxOut->address === $sAddress; });
                                                        if(count($aTemp) > 0)
                                                        {
                                                            $aReturn[] = $oTransaction->id;
                                                            break;
                                                        }
                                                    }
                                                    
                                                    foreach($oTransaction->txOuts AS $oTxOut)
                                                    {
                                                        if($oTxOut->address == $sAddress)
                                                        {
                                                            $aReturn[] = $oTransaction->id;
                                                            break;
                                                        }
                                                    }
                                                }
                                                
                                                $aTemp = array_unique($aReturn);
                                                unset($aReturn);
                                                array_walk_recursive($aTemp, function($v) use(&$aReturn) { $aReturn[] = $v; });
                                                
                                                $this->send(['txs' => $aReturn], $iKey);
                                            }
                                            break;
                                        case 'transaction':       // Return only the requested transaction (hash)
                                            if(!isset($aArguments[2]))
                                            {
                                                $this->send('', $iKey, 400);
                                            }
                                            else 
                                            {
                                                $sTxId = $aArguments[2];

                                                $aBlocks = [];
                                                $aTxMap = array_map(function(cBlock $oBlock) { return $oBlock->data; }, $cBlockchain->getBlockchain());
                                                array_walk_recursive($aTxMap, function($v) use(&$aBlocks) { $aBlocks[] = $v; });
                                                
                                                $oTransaction = _::find($aBlocks, function($oData) use($sTxId) { return ($oData->id == $sTxId); });
                                                if($oTransaction !== false)
                                                {
                                                    $findBlock = function(cBlock $oBlock) use($oTransaction) 
                                                    {
                                                        foreach($oBlock->data AS $oObject)
                                                        {
                                                            if($oObject->id == $oTransaction->id)
                                                            {
                                                                return $oBlock;
                                                            }
                                                        }
                                                    };
                                                    
                                                    $oFoundBlock = _::find($cBlockchain->getBlockchain(), $findBlock);
                                                    
                                                    $this->send(['transaction' => $oTransaction, 'block' => $oFoundBlock], $iKey);
                                                    break;
                                                }

                                                $this->send('', $iKey, 404);
                                            }
                                            break;
                                        case 'peers':      // Return all peers on the P2P server
                                            $aPeers = array_filter($cBlockchain->aClientsInfo, function($aClient) { return $aClient['protocol'] === 'p2p' ?? false; }); // Get all clients that are P2P (nodes)
                                            $aPeers = array_map(function($aPeer) { unset($aPeer['resource']); return $aPeer; }, $aPeers);                               // Unset resource in new array (json cannot encode a resource)
                                            $aPeers = array_values($aPeers);                                                                                            // Reset array keys 0,1,2...
                                            
                                            $this->send($aPeers, $iKey);
                                            break;
                                        case 'address':
                                            if(!isset($aArguments[2]))
                                            {
                                                $this->send('', $iKey, 400);
                                            }
                                            
                                            $sAddress = $aArguments[2];
                                            
                                            $aUnspentTxOuts = array_filter($cBlockchain->getUnspentTxOuts(), function(cUnspentTxOut $oUTxO) use($sAddress) { return ($oUTxO->address === $sAddress); });
                                            $this->send(['unspentTxOuts' => $aUnspentTxOuts], $iKey);
                                            break;
                                        case 'myAddress':
                                            $this->send(['address' => $cBlockchain->getPublicFromWallet()], $iKey);
                                            break;
                                        case 'myBalance':
                                            $this->send(['balance' => $cBlockchain->getAccountBalance()], $iKey);
                                            break;
                                        case 'balance':
                                            if(!isset($aArguments[2]))
                                            {
                                                $this->send('', $iKey, 400);
                                            }
                                            
                                            $this->send(['balance' => $cBlockchain->getBalance($aArguments[2], $cBlockchain->getUnspentTxOuts())], $iKey);
                                            break;
                                        case 'supply':
                                            $oLastBlock = $cBlockchain->getLastBlock();
                                            $iSupply = array_reduce($cBlockchain->getUnspentTxOuts(), function($iAmount, $oUnspentTxOut) { return $iAmount + $oUnspentTxOut->amount; }, 0);
                                            $this->send(['supply' => $iSupply, 'lastBlock' => $oLastBlock], $iKey);
                                            break;
                                        case 'transactionPool':
                                            $this->send($cBlockchain->getTransactionPool(), $iKey);
                                            break;
										case 'myUnspentTransactionOutputs':
										case 'unspentTransactionOutputs':
                                            break;
										default:
                                            $this->send('', $iKey, 404);
                                            break;
                                        }
                                    break;
                                case 'POST':
                                    $aArguments = explode("/", $sPage);
                                    unset($aArguments[0]); // Always empty
                                    
                                    if(count($aArguments) > 0)
                                    {
                                        switch($aArguments[1])
                                        {
                                            case 'mineBlock':
                                                try 
                                                {
                                                    $oNewBlock = $cBlockchain->generateNextBlock();
                                                    $this->send($oNewBlock, $iKey);
                                                }
                                                catch(Exception $e)
                                                {
                                                    $this->send([$e->getMessage()], $iKey, 400);
                                                }
                                                break;
                                            case 'addPeer':
                                                $rSocket = $this->connectToPeer((object)$oBody->data);
                                                if(is_resource($rSocket))
                                                {                                                   
                                                    $cBlockchain->aClientsInfo[] = ['resource' => $rSocket, 'ipaddr' => $oBody->data->address, 'port' => $oBody->data->port, 'protocol' => 'p2p'];
                                                    
                                                    // Send message to the host that added the peer
                                                    $this->send(['message' => "Connected with {$oBody->data->address}:{$oBody->data->port} succesfull {$rSocket}"], $iKey);
                                                    
                                                    // Send message to the newly added peer
                                                    end($cBlockchain->aClientsInfo);
                                                    $this->sendPeers($cBlockchain->queryChainLengthMsg(), key($cBlockchain->aClientsInfo));
                                                }
                                                else
                                                {
                                                    $this->send(['message' => "Connected with {$oBody->data->address}:{$oBody->data->port} failed"], $iKey);
                                                }
                                                break;
                                            case 'mineTransaction':
                                                try 
                                                {
                                                    $sAddress = ((isset($oBody->data->address)) ? $oBody->data->address : '');
                                                    $iAmount = ((isset($oBody->data->amount)) ? $oBody->data->amount : 0);
                                                    $oData = ((isset($oBody->data->data)) ? unserialize($oBody->data->data) : new stdClass());
                                                    
                                                    if(empty($sAddress))
                                                    {
                                                        throw new Exception("invalid address");
                                                    }
                                                    
                                                    $sResponse = $cBlockchain->generatenextBlockWithTransaction($sAddress, $iAmount, $oData);
                                                    
                                                    $this->send([$sResponse], $iKey);
                                                }
                                                catch(Exception $e)
                                                {
                                                    $this->send([$e->getMessage()], $iKey, 400);
                                                }
                                                break;
                                            case 'sendTransaction':
                                                try 
                                                {
                                                    $sAddress = ((isset($oBody->data->address)) ? $oBody->data->address : '');
                                                    $iAmount = ((isset($oBody->data->amount)) ? $oBody->data->amount : 0);
                                                    $oData = ((isset($oBody->data->data)) ? unserialize($oBody->data->data) : new stdClass());
                                                    
                                                    if(empty($sAddress))
                                                    {
                                                        throw new Exception("invalid address");
                                                    }
                                                    
                                                    $sResponse = $cBlockchain->sendTransaction($sAddress, $iAmount, $oData);
                                                    
                                                    $this->send([$sResponse], $iKey);
                                                }
                                                catch(Exception $e)
                                                {
                                                    $this->send([$e->getMessage()], $iKey, 400);
                                                }
                                                break;
                                            default:
                                                $this->send('', $iKey, 404);
                                                break;
                                        }
                                    }
                                    else
                                    {
                                        $this->send('', $iKey, 400);
                                    }
                                    break;
                                default:
                                    $this->send('', $iKey, 404);
                                    break;
                            }
                        }
                        else
                        {
                            $this->send('', $iKey, 404);
                        }
                    }
                    elseif($aClient['protocol'] == 'p2p')
                    {
                        $oMessage = unserialize(trim($sBuffer));
                        
                        $iMessageType = (int)$oMessage->type;
                        $oMessageData = unserialize($oMessage->data);

                        switch($iMessageType)
                        {
                            case cP2PServer::QUERY_LATEST:
                                self::debug("QUERY_LATEST: Sending out responseLatestMsg()");
                                $this->sendPeers($cBlockchain->responseLatestMsg(), $iKey);
                                break;
                            case cP2PServer::QUERY_ALL:
                                self::debug("QUERY_ALL: Sending out responseChainMsg()");
                                $this->sendPeers($cBlockchain->responseChainMsg(), $iKey);
                                break;
                            case cP2PServer::RESPONSE_BLOCKCHAIN:
                                self::debug("RESPONSE_BLOCKCHAIN: handleBlockchainResponse()");
                                $cBlockchain->handleBlockchainResponse($oMessageData);
                                break;  
                            case cP2PServer::QUERY_TRANSACTION_POOL:
                                self::debug("QUERY_TRANSACTION_POOL: responseTransactionPoolMsg()");
                                $this->sendPeers($cBlockchain->responseTransactionPoolMsg(), $iKey);
                                break;
                            case cP2PServer::RESPONSE_TRANSACTION_POOL:
                                if(!is_array($oMessageData))
                                {
                                    self::debug("invalid transaction received: ".json_encode($oMessageData));
                                    break;
                                }
                                foreach($oMessageData AS $oTransaction)
                                {
                                    try
                                    {
                                        self::debug("RESPONSE_TRANSACTION_POOL: handleReceivedTransaction()");
                                        $cBlockchain->handleReceivedTransaction($oTransaction);
                                        $cBlockchain->broadCastTransactionPool();
                                    }
                                    catch(Exception $e)
                                    {
                                        self::debug("Error RESPONSE_TRANSACTION_POOL: {$e->getMessage()}");
                                    }
                                }
                                break;
                            default:
                                $this->sendPeers('', $iKey);
                                break;
                        }
                    }
                    else
                    {
                        $this->send('', $iKey, 404);
                    }
                }
            }
        }
    }
}