<?php
trait tSocketServer
{
    use tUtils;
    
    private function createSocket()
    {
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
    }
       
    private function send($rSocket, $aData, $iKey, $sHttpCode = "200 OK")
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
    
    private function closeConnection($iKey)
    {
        self::debug("* Disconnected client {$this->aClientsInfo[$iKey]['ipaddr']}...");
        
        @socket_close($this->aClients[$iKey]);
        unset($this->aClients[$iKey]);
        unset($this->aClientsInfo[$iKey]);
    }
}
?>