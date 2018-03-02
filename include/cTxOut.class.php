<?php
class cTxOut
{
    /**
     * @var string
     */
    public $address;
    
    /**
     * @var integer
     */
    public $amount;
    
    /**
     * @var object
     */
    public $dataObject;
    
    public function __construct(string $sAddress, int $iAmount, stdClass $oDataObject)
    {
        $this->address      = (string)$sAddress;
        $this->amount       = (int)$iAmount;
        $this->dataObject   = (object)$oDataObject;
    }
}
?>