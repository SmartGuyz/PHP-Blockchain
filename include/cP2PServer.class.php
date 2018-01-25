<?php
class cP2PServer
{
    use tSocketServer;
    
    protected $aClients, $aClientsInfo, $aRead, $aConfig, $rMasterSocket;
    private $iMaxClients, $iMaxRead, $cBlockchain;
    
    public function __construct(string $sRemoteAddress, int $iRemotePort)
    {
        $this->aClients         = [];
        $this->aClientsInfo     = [];
        $this->aConfig["ip"]    = $sRemoteAddress;
        $this->aConfig["port"]  = $iRemotePort;
        
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
                    
                    self::debug("\n[{$sClientIP}:{$sClientPort}] >> {$sBuffer}");
                }
            }
        }
    }
}
?>