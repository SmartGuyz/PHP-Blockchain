<?php
class cUnspentTxOut
{
    /**
     * @var string
     */
    protected $txOutId;
    
    /**
     * @var integer
     */
    protected $txOutIndex;
    
    /**
     * @var string
     */
    protected $address;
    
    /**
     * @var integer
     */
    protected $amount;
    
    public function __construct(string $sTxOutId, integer $iTxOutIndex, string $sAddress, integer $iAmount)
    {
        $this->txOutId      = (string)$sTxOutId;
        $this->txOutIndex   = (int)$iTxOutIndex;
        $this->address      = (string)$sAddress;
        $this->amount       = (int)$iAmount;
    }
}
?>