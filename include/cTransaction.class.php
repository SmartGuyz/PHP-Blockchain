<?php
class cTransaction
{
    /**
     * @var string
     */
    public $id;
       
    /**
     * @var cTxIn[]
     */
    public $txIns = [];
    
    /**
     * @var cTxOut[]
     */
    public $txOuts = [];
}
?>