<?php
class cTxOut
{
    /**
     * @var string
     */
    public string $address;
    
    /**
     * @var integer
     */
    public int $amount;
    
    /**
     * @var object
     */
    public object $dataObject;
    
    public function __construct(string $sAddress, int $iAmount, object $oDataObject)
    {
        $this->address      = $sAddress;
        $this->amount       = $iAmount;
        $this->dataObject   = $oDataObject;
    }
}