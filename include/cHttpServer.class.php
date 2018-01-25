<?php
class cHttpServer
{
    use tSocketServer;
    
    protected $aClients, $aClientsInfo, $aRead, $aConfig, $rMasterSocket;
    private $iMaxClients, $iMaxRead, $cBlockchain, $cSwitchBoard;
    
    public function __construct()
    {
        $this->aClients         = [];
        $this->aClientsInfo     = [];
        $this->aConfig["ip"]    = "0.0.0.0";
        $this->aConfig["port"]  = 3001;
        
        $this->iMaxClients      = 1024;
        $this->iMaxRead         = 1024;
        
        $this->createSocket();
    }
    
    public function run(cBlockchain $oBlockchain)
    {
        $this->cBlockchain = $oBlockchain;
        
        while(true)
        {
            $this->aRead = [];
            $this->aRead[] = $this->rMasterSocket;
            
            $this->aRead = array_merge($this->aRead, $this->aClients);
            
            // Zet blocking via socket_select
            $sNull = null;
            if(@socket_select($this->aRead, $sNull, $sNull, $sNull) < 1)
            {
                self::debug("Problem blocking socket_select?");
                continue;
            }
            
            // Handle nieuwe verbindingen
            if(in_array($this->rMasterSocket, $this->aRead))
            {
                if(($rMsgSocket = @socket_accept($this->rMasterSocket)) === false)
                {
                    self::debug("socket_accept() failed: reason: ".socket_strerror(socket_last_error($this->rMasterSocket)));
                    break;
                }
                else
                {
                    socket_set_option($rMsgSocket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
                    socket_set_option($rMsgSocket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);
                    
                    socket_getpeername($rMsgSocket, $sClientIP, $sClientPort);
                    echo "* Incoming connection from {$sClientIP} on port {$sClientPort}\n";
                    
                    $this->aClients[] = $rMsgSocket;
                    $this->aClientsInfo[] = ['ipaddr' => $sClientIP, 'port' => $sClientPort];
                }
            }
            
            // Handle nieuwe input
            foreach($this->aClients AS $iKey => $rClient)
            {
                if(in_array($rClient, $this->aRead))
                {
                    if(false === ($sBuffer = socket_read($rClient, 1024, PHP_BINARY_READ)))
                    {
                        self::debug("socket_read() failed: reason: ".socket_strerror(socket_last_error($rClient)));
                        break 2;
                    }
                    
                    if($sBuffer == null)
                    {
                        $this->closeConnection($iKey);
                        continue;
                    }
                    
                    socket_getpeername($rClient, $sClientIP, $sClientPort);

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
                    self::debug("\n[{$sClientIP}:{$sClientPort}] >> {$aIncoming[0]}");
                    
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
                                        $this->send($rClient, $this->cBlockchain->getBlockchain(), $iKey);
                                        break;
                                    case 'block':       // Return only the requested block (hash)
                                        if(!isset($aArguments[2]))
                                        {
                                            $this->send($rClient, ['error' => 'Block hash invalid'], $iKey, "400 Bad Request");
                                        }
                                        else 
                                        {
                                            $iChainKey = array_search($aArguments[2], array_column($this->cBlockchain->getBlockchain(), 'hash'));
                                            if($iChainKey !== false)
                                            {
                                                $this->send($rClient, $this->cBlockchain->getBlockchain()[$iChainKey], $iKey);
                                            }
                                            else
                                            {
                                                $this->send($rClient, ['error' => 'Block hash not found'], $iKey, "404 Not found");
                                            }
                                        }
                                        break;
                                    case 'search':       // Searching the blockchain for data
                                        if(!isset($aArguments[2]))
                                        {
                                            $this->send($rClient, ['error' => 'Search argument invalid'], $iKey, "400 Bad Request");
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
                                                $this->send($rClient, $aTemp, $iKey);
                                                
                                                unset($aBlockchain);
                                                unset($aTemp);
                                            }
                                            else
                                            {
                                                $this->send($rClient, ['error' => 'Search argument not found'], $iKey, "404 Not found");
                                            }
                                        }
                                        break;
                                    default:
                                        $this->send($rClient, ['error' => 'Argument not found'], $iKey, "404 Not found");
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
                                            $this->send($rClient, $oNewBlock, $iKey);
                                            break;
                                        default:
                                            $this->send($rClient, ['error' => 'Argument not found'], $iKey, "404 Not found");
                                            break;
                                    }
                                }
                                else
                                {
                                    $this->send($rClient, ['error' => 'Argument not found'], $iKey, "400 Bad Request");
                                }
                                break;
                            default:
                                $this->send($rClient, ['error' => 'Method not found'], $iKey, "404 Not found");
                                break;
                        }
                    }
                    else
                    {
                        $this->send($rClient, ['error' => 'Argument not found'], $iKey, "404 Not found");
                    }
                }
            }
        }
    }
}
?>