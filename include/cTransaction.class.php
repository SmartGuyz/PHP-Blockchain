<?php
class cTransaction
{
    /**
     * @var string
     */
    public $id;
    
    /**
     * @var int
     */
    public $timestamp;
       
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