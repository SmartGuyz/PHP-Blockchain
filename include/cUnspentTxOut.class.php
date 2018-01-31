<?php
class cUnspentTxOut 
{
    protected $txOutId, $txOutIndex, $address, $amount;
    
    public function __construct(string $txOutId, int $txOutIndex, string $address, int $amount) 
    {
        $this->txOutId      = $txOutId;
        $this->txOutIndex   = $txOutIndex;
        $this->address      = $address;
        $this->amount       = $amount;
    }
}
?>