<?php
trait tSocketServer
{
    use tUtils;
    
    private function connectToPeer(stdClass $oData)
    {
        $rSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        // Set timeout
        socket_set_option($rSocket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        socket_set_option($rSocket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);
        
        socket_set_nonblock($rSocket); 
        
        @socket_connect($rSocket, $oData->address, $oData->port);
        
        /*$iAttempts = 0;
        $bConnected; 
        while(!($bConnected = @socket_connect($rSocket, $oData->address, $oData->port)) && $iAttempts < 3) 
        {
            if(socket_last_error() != SOCKET_EINPROGRESS && socket_last_error() != SOCKET_EALREADY) 
            {
                socket_close($rSocket);
                return false;
            }
            $iAttempts++;
            usleep(1000);
        }
        
        if(!$bConnected) 
        {
            socket_close($rSocket);
            return false;
        }
        */
        socket_set_block($rSocket); 
        
        return $rSocket;
    }
        
    /**
     * Bind IP and port for http and P2P server
     * 
     * @param array $aIniValues
     */
    private function createSocket(array $aIniValues): void
    {
        foreach($aIniValues AS $iKey => $aValue)
        {
            if(($this->rMasterSocket[$iKey] = @socket_create(AF_INET, SOCK_STREAM, 0)) === false)
            {
                self::debug("socket_create() failed: reason: ".socket_strerror(socket_last_error()));
            }
            else
            {
                self::debug("* Socket created!");
            }
            
            if(@socket_set_option($this->rMasterSocket[$iKey], SOL_SOCKET, SO_REUSEADDR, 1) === false)
            {
                self::debug("socket_set_option() failed: reason: ".socket_strerror(socket_last_error($this->rMasterSocket[$iKey])));
            }
            else
            {
                self::debug("Set option SO_REUSEADDR!");
            }

            if(@socket_bind($this->rMasterSocket[$iKey], $aValue['ip'], $aValue['port']) === false)
            {
                self::debug("socket_bind() failed: reason: ".socket_strerror(socket_last_error($this->rMasterSocket[$iKey])));
            }
            else
            {
                self::debug("Socket bind to {$aValue['ip']}:{$aValue['port']}!");
            }
            
            if(@socket_listen($this->rMasterSocket[$iKey]) === false)
            {
                self::debug("socket_listen() failed: reason: ".socket_strerror(socket_last_error($this->rMasterSocket[$iKey])));
                exit;
            }
            else
            {
                self::debug("Socket is now listening, waiting for clients to connect...");
            }
        }
    }
       
    private function send($aData, $iKey, $iHttpCode = 200)
    {
        switch($iHttpCode) 
        {
            case 100: $sHttpCode = 'Continue'; break;
            case 101: $sHttpCode = 'Switching Protocols'; break;
            case 200: $sHttpCode = 'OK'; break;
            case 201: $sHttpCode = 'Created'; break;
            case 202: $sHttpCode = 'Accepted'; break;
            case 203: $sHttpCode = 'Non-Authoritative Information'; break;
            case 204: $sHttpCode = 'No Content'; break;
            case 205: $sHttpCode = 'Reset Content'; break;
            case 206: $sHttpCode = 'Partial Content'; break;
            case 300: $sHttpCode = 'Multiple Choices'; break;
            case 301: $sHttpCode = 'Moved Permanently'; break;
            case 302: $sHttpCode = 'Moved Temporarily'; break;
            case 303: $sHttpCode = 'See Other'; break;
            case 304: $sHttpCode = 'Not Modified'; break;
            case 305: $sHttpCode = 'Use Proxy'; break;
            case 400: $sHttpCode = 'Bad Request'; break;
            case 401: $sHttpCode = 'Unauthorized'; break;
            case 402: $sHttpCode = 'Payment Required'; break;
            case 403: $sHttpCode = 'Forbidden'; break;
            case 404: $sHttpCode = 'Not Found'; break;
            case 405: $sHttpCode = 'Method Not Allowed'; break;
            case 406: $sHttpCode = 'Not Acceptable'; break;
            case 407: $sHttpCode = 'Proxy Authentication Required'; break;
            case 408: $sHttpCode = 'Request Time-out'; break;
            case 409: $sHttpCode = 'Conflict'; break;
            case 410: $sHttpCode = 'Gone'; break;
            case 411: $sHttpCode = 'Length Required'; break;
            case 412: $sHttpCode = 'Precondition Failed'; break;
            case 413: $sHttpCode = 'Request Entity Too Large'; break;
            case 414: $sHttpCode = 'Request-URI Too Large'; break;
            case 415: $sHttpCode = 'Unsupported Media Type'; break;
            case 500: $sHttpCode = 'Internal Server Error'; break;
            case 501: $sHttpCode = 'Not Implemented'; break;
            case 502: $sHttpCode = 'Bad Gateway'; break;
            case 503: $sHttpCode = 'Service Unavailable'; break;
            case 504: $sHttpCode = 'Gateway Time-out'; break;
            case 505: $sHttpCode = 'HTTP Version not supported'; break;
        }
        
        $sHeader = "HTTP/1.1 {$iHttpCode} {$sHttpCode}\r\n";
        $sHeader .= "Date: Fri, 31 Dec 1999 23:59:59 GMT\r\n";
        $sHeader .= "Server: PHPBC/1.0\r\n";
        $sHeader .= "X-Powered-By: PHP/".phpversion()."\r\n";
        $sHeader .= (($iHttpCode == 200) ? "Content-Type: application/json; charset=utf-8\r\n\r\n" : "Content-Type: text/html; charset=utf-8\r\n");
        
        $sData = (($iHttpCode == 200) ? $sHeader.json_encode($aData, JSON_PRETTY_PRINT) : $sHeader);
        
        socket_send($this->aClientsInfo[$iKey]['resource'], $sData, strlen($sData), MSG_EOF);
        
        $this->closeConnection($iKey);
    }
    
    private function sendPeers($oData, $iKey)
    {
        self::debug("Send {$oData->type} to peer");
        
        $sData = serialize($oData);
        socket_send($this->aClientsInfo[$iKey]['resource'], $sData, strlen($sData), MSG_EOF);
    }
    
    private function closeConnection($iKey)
    {
        self::debug("Disconnected client {$this->aClientsInfo[$iKey]['ipaddr']}...");
        
        @socket_close($this->aClientsInfo[$iKey]['resource']);
        unset($this->aClientsInfo[$iKey]);
    }
}
?>