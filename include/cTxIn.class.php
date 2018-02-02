<?php
class cTxIn
{
    /**
     * @var string
     */
    public $txOutId;
    
    /**
     * @var int
     */
    public $txOutIndex;
    
    /**
     * @var string
     */
    public $signature;
    
    /**
     * @var string
     */
    public $fromAddress;
    
    /**
     * @var string
     */
    public $toAddress;
    
    /**
     * @var object
     */
    public $dataObject;
    
    public function __construct(string $txOutId, int $txOutIndex, string $signature, string $fromAddress, string $toAddress, stdClass $dataObject)
    {
        $this->txOutId      = $txOutId;
        $this->txOutIndex   = $txOutIndex;
        $this->signature    = $signature;
        $this->fromAddress  = $signature;
        $this->toAddress    = $signature;
        $this->dataObject   = $dataObject;
    }
}
?>