<?php
class cTxOut 
{
    /**
     * @var string
     */
    public $address;
    
    /**
     * @var int
     */
    public $amount;
    
    public function __construct(string $sAddress, int $iAmount) 
    {
        $this->address  = $sAddress;
        $this->amount   = $iAmount;
    }
}
?>