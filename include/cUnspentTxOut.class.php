<?php
class cUnspentTxOut
{
    /**
     * @var string
     */
    public $txOutId;
    
    /**
     * @var integer
     */
    public $txOutIndex;
    
    /**
     * @var string
     */
    public $address;
    
    /**
     * @var integer
     */
    public $amount;
    
    public function __construct(string $sTxOutId, int $iTxOutIndex, string $sAddress, int $iAmount)
    {
        $this->txOutId      = (string)$sTxOutId;
        $this->txOutIndex   = (int)$iTxOutIndex;
        $this->address      = (string)$sAddress;
        $this->amount       = (int)$iAmount;
    }
}
?>