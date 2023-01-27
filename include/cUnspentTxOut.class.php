<?php
class cUnspentTxOut
{
    /**
     * @var string
     */
    public string $txOutId;
    
    /**
     * @var integer
     */
    public int $txOutIndex;
    
    /**
     * @var string
     */
    public string $address;
    
    /**
     * @var integer
     */
    public int $amount;
    
    public function __construct(string $sTxOutId, int $iTxOutIndex, string $sAddress, int $iAmount)
    {
        $this->txOutId      = $sTxOutId;
        $this->txOutIndex   = $iTxOutIndex;
        $this->address      = $sAddress;
        $this->amount       = $iAmount;
    }
}