<?php
use Underscore\Underscore as _;

class cHttpServer
{
    use tSocketServer;
    
    protected $aRead, $rMasterSocket;
    private $iMaxClients, $iMaxRead, $cBlockchain, $aIniValues;
    
    public function __construct(array $aConfig)
    {
        $this->iMaxClients  = 1024;
        $this->iMaxRead     = 1024;
        
        $this->aIniValues   = $aConfig;
        
        $this->createSocket($this->aIniValues);
    }
    
    public function run(cBlockchain $oBlockchain)
    {
        $this->cBlockchain = $oBlockchain;
        
        foreach($this->aIniValues AS $i => $aValue)
        {
            socket_getsockname($this->rMasterSocket[$i], $sServerIP, $sServerPort);
            
            $this->cBlockchain->aClientsInfo[] = ['resource' => $this->rMasterSocket[$i], 'ipaddr' => $sServerIP, 'port' => $sServerPort, 'protocol' => 'master'];
        }
        
        while(true)
        {
            $this->aRead = [];
            $this->aRead = array_column($this->cBlockchain->aClientsInfo, 'resource');
            
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

                        $this->cBlockchain->aClientsInfo[] = ['resource' => $rMsgSocket, 'ipaddr' => $sClientIP, 'port' => $sClientPort, 'protocol' => $aValue['name']];
                    }
                }
            }
            
            // Handle nieuwe input
            foreach($this->cBlockchain->aClientsInfo AS $iKey => $aClient)
            {
                if($aClient['protocol'] == 'master')
                {
                    continue;
                }
                
                if(in_array($aClient['resource'], $this->aRead))
                {  
                    $sBuffer = null;
                    while(($iFlag = @socket_recv($aClient['resource'], $sTempBuffer, 1024, 0)) > 0) 
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

                    if(empty($sBuffer) OR $sBuffer == '' OR $sBuffer == null)
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
                        $bJson = false;
                        $aBody = explode("\r\n\r\n", $sBuffer);
                        $sBody = ((isset($aBody[1])) ? $aBody[1] : "");
                        if(@json_decode(trim($sBody)))
                        {
                            $bJson = true;
                            $oBody = json_decode(trim($sBody));
                        }
                        
                        // Get page and method
                        preg_match('~.+?(?=\sHTTP\/1\.1)~is', $aIncoming[0], $aMatches);
                        list($sMethod, $sPage) = explode(" ", $aMatches[0]);
                        
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
                                            $this->send($this->cBlockchain->getBlockchain(), $iKey);
                                            break;
                                        case 'block':       // Return only the requested block (hash)
                                            if(!isset($aArguments[2]))
                                            {
                                                $this->send('', $iKey, 400);
                                            }
                                            else 
                                            {
                                                $sHashToSearch = trim($aArguments[2]);
                                                $aBlock = _::find($this->cBlockchain->getBlockchain(), function(cBlock $oBlock) use ($sHashToSearch) { return ($oBlock->hash === $sHashToSearch); });
                                                $this->send((($aBlock === null) ? ['error' => 'Block hash found'] : $aBlock), $iKey);
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
                                                $aTxMap = array_map(function(cBlock $oBlock) { return $oBlock->data; }, $this->cBlockchain->getBlockchain());
                                                array_walk_recursive($aTxMap, function($v, $k) use(&$aBlocks) { $aBlocks[] = $v; });
                                                
                                                $oTransaction = _::find($aBlocks, function($oData) use($sTxId) { return ($oData->id == $sTxId); });
                                                if($oTransaction !== false)
                                                {
                                                    $this->send($oTransaction, $iKey);
                                                    break;
                                                }

                                                $this->send('', $iKey, 404);
                                            }
                                            break;
                                        case 'peers':      // Return all peers on the P2P server
                                            $aPeersKeys = preg_grep("/p2p/i", array_column($this->cBlockchain->aClientsInfo, 'protocol'));
                                            if($aPeersKeys !== false && !empty($aPeersKeys) && count($aPeersKeys) > 0)
                                            {
                                                foreach($aPeersKeys AS $iTempClient => $aTempClient)
                                                {
                                                    $aTemp[] = "{$this->cBlockchain->aClientsInfo[$iTempClient]['ipaddr']}:{$this->cBlockchain->aClientsInfo[$iTempClient]['port']}";
                                                }
                                            }
                                            $this->send(((!isset($aTemp) OR count($aTemp) == 0) ? ['error' => 'No peers found'] : $aTemp), $iKey);
                                            
                                            unset($aTemp);
                                            break;
                                        case 'address':
                                            $this->send(['address' => $this->cBlockchain->getPublicFromWallet()], $iKey);
                                            break;
                                        case 'balance':
                                            $this->send(['balance' => $this->cBlockchain->getAccountBalance()], $iKey);
                                            break;
                                        case 'transactionPool':
                                            $this->send($this->cBlockchain->getTransactionPool(), $iKey);
                                            break;
                                        case 'unspentTransactionOutputs':
                                            break;
                                        case 'myUnspentTransactionOutputs':
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
                                                    $oNewBlock = $this->cBlockchain->generateNextBlock();
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
                                                    $this->cBlockchain->aClientsInfo[] = ['resource' => $rSocket, 'ipaddr' => $oBody->data->address, 'port' => $oBody->data->port, 'protocol' => 'p2p'];
                                                    
                                                    // Send message to the host that added the peer
                                                    $this->send(['message' => "Connected with {$oBody->data->address}:{$oBody->data->port} succesfull {$rSocket}"], $iKey);
                                                    
                                                    // Send message to the newly added peer
                                                    end($this->cBlockchain->aClientsInfo);
                                                    $this->sendPeers($this->cBlockchain->queryChainLengthMsg(), key($this->cBlockchain->aClientsInfo));
                                                }
                                                else
                                                {
                                                    $this->send(['message' => "Connected with {$oBody->data->address}:{$oBody->data->port} failed"], $iKey);
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
                                                        throw new Exception("invalid address ({$oBody->data->address})");
                                                    }
                                                    
                                                    $sResponse = $this->cBlockchain->sendTransaction($sAddress, $iAmount, $oData);
                                                    
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
                                $this->sendPeers($this->cBlockchain->responseLatestMsg(), $iKey);
                                break;
                            case cP2PServer::QUERY_ALL:
                                self::debug("QUERY_ALL: Sending out responseChainMsg()");
                                $this->sendPeers($this->cBlockchain->responseChainMsg(), $iKey);
                                break;
                            case cP2PServer::RESPONSE_BLOCKCHAIN:
                                self::debug("RESPONSE_BLOCKCHAIN: handleBlockchainResponse()");
                                $this->cBlockchain->handleBlockchainResponse($oMessageData);
                                break;  
                            case cP2PServer::QUERY_TRANSACTION_POOL:
                                self::debug("QUERY_TRANSACTION_POOL: responseTransactionPoolMsg()");
                                $this->sendPeers($this->cBlockchain->responseTransactionPoolMsg(), $iKey);
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
                                        $this->cBlockchain->handleReceivedTransaction($oTransaction);
                                        $this->cBlockchain->broadCastTransactionPool();
                                    }
                                    catch(Exception $e)
                                    {
                                        self::debug("Error RESPONSE_TRANSACTION_POOL: {$e->getMessage()}");
                                    }
                                }
                                break;
                            default:
                                $this->sendPeers('', $iKey, 404);
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
?>