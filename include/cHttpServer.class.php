<?php
class cHttpServer
{
    protected $aClients, $aClientsInfo, $aRead, $aConfig, $rMasterSocket;
    private $iMaxClients, $iMaxRead, $cBlockchain;
    
    final public function __construct()
    {
        //register_shutdown_function('abort');
        $this->aClients         = [];
        $this->aClientsInfo     = [];
        $this->aConfig["ip"]    = "0.0.0.0";
        $this->aConfig["port"]  = 3001;
        
        $this->iMaxClients      = 1024;
        $this->iMaxRead         = 1024;
        
        $this->cBlockchain      = new cBlockchain();
               
        if(($this->rMasterSocket = @socket_create(AF_INET, SOCK_STREAM, 0)) === false)
        {
            return "socket_create() failed: reason: ".socket_strerror(socket_last_error());
        }
        else
        {
            self::debug("* Socket created!");
        }
        
        if(@socket_set_option($this->rMasterSocket, SOL_SOCKET, SO_REUSEADDR, 1) === false)
        {
            return "socket_set_option() failed: reason: ".socket_strerror(socket_last_error($this->rMasterSocket));
        }
        else
        {
            self::debug("* Set option SO_REUSEADDR!");
        }
        
        if(@socket_bind($this->rMasterSocket, $this->aConfig["ip"], $this->aConfig["port"]) === false)
        {
            return "socket_bind() failed: reason: ".socket_strerror(socket_last_error($this->rMasterSocket));
        }
        else
        {
            self::debug("* Socket bind to {$this->aConfig["ip"]}:{$this->aConfig["port"]}!");
        }
        
        if(@socket_listen($this->rMasterSocket) === false)
        {
            return "socket_listen() failed: reason: ".socket_strerror(socket_last_error($this->rMasterSocket));
            exit;
        }
        else
        {
            self::debug("* Socket is now listening, waiting for clients to connect...".PHP_EOL);
        }
        
        return;
    }
    
    final public function run()
    {
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
                    
                    self::debug("\n[{$sClientIP}:{$sClientPort}] >> {$sBuffer}");

                    $aIncoming = explode("\r\n", $sBuffer);
                    $aFetchArray = explode(" ", $aIncoming[0]);
                    
                    // Get page and method
                    preg_match('~.+?(?=\sHTTP\/1\.1)~is', $aIncoming[0], $aMatches);
                    list($sMethod, $sPage) = explode(" ", $aMatches[0]);
                    
                    switch($sMethod)
                    {
                        case 'GET':
                            $aArguments = explode("/", $sPage);
                            unset($aArguments[0]); // Always empty
                            
                            if(count($aArguments) > 0)
                            {
                                switch($aArguments[1])
                                {
                                    case 'blocks':      // Return all blocks of the chain
                                        $this->send($rClient, $this->cBlockchain->getBlockchain(), $iKey);
                                        break;
                                    case 'block':       // Return only the requested block (hash)
                                        if(!isset($aArguments[2]) OR strlen($aArguments[2]) <> 64)
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
                                }
                            }
                            else
                            {
                                $this->send($rClient, ['error' => 'Method not found'], $iKey, "400 Bad Request");
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
                                        $oNewBlock = $this->cBlockchain->generateNextBlock("Test block"); // TODO: Post data afvangen
                                        $this->send($rClient, $oNewBlock, $iKey);
                                        break;
                                }
                            }
                            else
                            {
                                $this->send($rClient, ['error' => 'Method not found'], $iKey, "400 Bad Request");
                            }
                            break;
                        default:
                            $this->send($rClient, ['error' => 'Method not found'], $iKey, "400 Bad Request");
                            break;
                    }

                    unset($aTempData);
                }
            }
        }
    }

    final private static function debug($sData)
    {
        echo "{$sData}".PHP_EOL;
    }

    final private function send($rSocket, $aData, $iKey, $sHttpCode = "200 OK")
    {
        $sHeader = "HTTP/1.1 {$sHttpCode}\r\n";
        $sHeader .= "Date: Fri, 31 Dec 1999 23:59:59 GMT\r\n";
        $sHeader .= "Server: PHPBC/1.0\r\n";
        $sHeader .= "X-Powered-By: PHP/".phpversion()."\r\n";
        $sHeader .= "Content-Type: application/json; charset=utf-8\r\n\r\n";
        
        $sData = $sHeader.json_encode($aData, JSON_PRETTY_PRINT);
        
        socket_send($rSocket, $sData, strlen($sData), MSG_EOF);
        self::debug("[{$this->aClientsInfo[$iKey]['ipaddr']}:{$this->aClientsInfo[$iKey]['port']}] << {$sData}");
        
        $this->closeConnection($iKey);
    }

    final private function closeConnection($iKey)
    {
        self::debug("* Disconnected client {$this->aClientsInfo[$iKey]['ipaddr']}...");

        @socket_close($this->aClients[$iKey]);
        unset($this->aClients[$iKey]);
        unset($this->aClientsInfo[$iKey]);
    }
}
?>