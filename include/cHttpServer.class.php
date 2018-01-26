<?php
class cHttpServer extends cP2PServer
{
    use tSocketServer;
    
    protected $aClientsInfo, $aRead, $rMasterSocket;
    private $iMaxClients, $iMaxRead, $cBlockchain, $aIniValues;
    
    public function __construct(array $aConfig)
    {
        $this->aClientsInfo     = [];
        
        $this->iMaxClients      = 1024;
        $this->iMaxRead         = 1024;
        
        $this->aIniValues       = $aConfig;
        
        $this->createSocket($this->aIniValues);
    }
    
    public function run(cBlockchain $oBlockchain)
    {
        $this->cBlockchain = $oBlockchain;
        
        while(true)
        {
            $this->aRead = [];
            foreach($this->aIniValues AS $iKey => $aValue)
            {
                $this->aRead[] = $this->rMasterSocket[$iKey];
            }
            
            $this->aRead = array_merge($this->aRead, array_column($this->aClientsInfo, 'resource'));
            
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
                        
                        // Serv info
                        socket_getsockname($rMsgSocket, $sServerIP, $sServerPort);
                        
                        self::debug("Incoming connection from {$sClientIP}:{$sClientPort} on {$sServerIP}:{$sServerPort} ({$aValue['name']})");

                        $this->aClientsInfo[] = ['resource' => $rMsgSocket, 'ipaddr' => $sClientIP, 'port' => $sClientPort, 'protocol' => $aValue['name']];
                    }
                }
            }
            
            // Handle nieuwe input
            foreach($this->aClientsInfo AS $iKey => $aClient)
            {
                if(in_array($aClient['resource'], $this->aRead))
                {
                    if(false === ($sBuffer = socket_read($aClient['resource'], 1024, PHP_BINARY_READ)))
                    {
                        self::debug("socket_read() failed: reason: ".socket_strerror(socket_last_error($aClient['resource'])));
                        break 2;
                    }
                    
                    if($sBuffer == null)
                    {
                        $this->closeConnection($iKey);
                        continue;
                    }

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
                    
                    // Debug
                    self::debug("[{$aClient['ipaddr']}:{$aClient['port']}] >> {$aIncoming[0]}");
                    
                    // check if we have any arguments (pages)
                    if(count($aArguments) > 0)
                    {
                        if($aClient['protocol'] == 'http')
                        {
                            // POST or GET?
                            switch($sMethod)
                            {
                                case 'GET':
                                    switch($aArguments[1])
                                    {
                                        case 'blocks':      // Return all blocks of the chain
                                            $this->send($aClient['resource'], $this->cBlockchain->getBlockchain(), $iKey);
                                            break;
                                        case 'block':       // Return only the requested block (hash)
                                            if(!isset($aArguments[2]))
                                            {
                                                $this->send($aClient['resource'], '', $iKey, 400);
                                            }
                                            else 
                                            {
                                                $iChainKey = array_search($aArguments[2], array_column($this->cBlockchain->getBlockchain(), 'hash'));
                                                if($iChainKey !== false)
                                                {
                                                    $this->send($aClient['resource'], $this->cBlockchain->getBlockchain()[$iChainKey], $iKey);
                                                }
                                                else
                                                {
                                                    $this->send($aClient['resource'], '', $iKey, 404);
                                                }
                                            }
                                            break;
                                        case 'peers':      // Return all peers on the P2P server
                                            $aP2PKeys = preg_grep("/p2p/", array_column($this->aClientsInfo, 'protocol'));
                                            if($aP2PKeys !== false)
                                            {
                                                foreach($this->aClientsInfo AS $iClient => $aClient)
                                                {
                                                    $aTemp[] = $aClient['ipaddr'].":".$aClient['port'];
                                                }
                                                $this->send($aClient['resource'], ((count($aTemp) == 0) ? ['error' => 'No peers found'] : $aTemp), $iKey);
                                                
                                                unset($aP2PKeys);
                                                unset($aTemp);
                                            }
                                            else
                                            {
                                                $this->send($aClient['resource'], '', $iKey, 404);
                                            }
                                            break;
                                        case 'search':       // Searching the blockchain for data
                                            if(!isset($aArguments[2]))
                                            {
                                                $this->send($aClient['resource'], '', $iKey, 400);
                                            }
                                            else 
                                            {
                                                $aChainKeys = preg_grep("/{$aArguments[2]}/i", array_column($this->cBlockchain->getBlockchain(), 'data'));
                                                if($aChainKeys !== false && !empty($aChainKeys) && count($aChainKeys) > 0)
                                                {
                                                    $aBlockchain = $this->cBlockchain->getBlockchain();
                                                    foreach($aChainKeys AS $iChainKey => $sValue)
                                                    {
                                                        $aTemp[] = $aBlockchain[$iChainKey];
                                                    }
                                                    $this->send($aClient['resource'], $aTemp, $iKey);
                                                    
                                                    unset($aChainKeys);
                                                    unset($aBlockchain);
                                                    unset($aTemp);
                                                }
                                                else
                                                {
                                                    $this->send($aClient['resource'], '', $iKey, 404);
                                                }
                                            }
                                            break;
                                        default:
                                            $this->send($aClient['resource'], '', $iKey, 404);
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
                                                $oNewBlock = $this->cBlockchain->generateNextBlock((string)$oBody->data); // TODO: Post data afvangen
                                                $this->send($aClient['resource'], $oNewBlock, $iKey);
                                                break;
                                            default:
                                                $this->send($aClient['resource'], '', $iKey, 404);
                                                break;
                                        }
                                    }
                                    else
                                    {
                                        $this->send($aClient['resource'], '', $iKey, 400);
                                    }
                                    break;
                                default:
                                    $this->send($aClient['resource'], '', $iKey, 404);
                                    break;
                            }
                        }
                        elseif($aClient['protocol'] == 'p2p')
                        {
                            // TODO: P2P Server
                        }
                        else
                        {
                            $this->send($aClient['resource'], '', $iKey, 404);
                        }
                    }
                    else
                    {
                        $this->send($aClient['resource'], '', $iKey, 404);
                    }
                }
            }
        }
    }
}
?>