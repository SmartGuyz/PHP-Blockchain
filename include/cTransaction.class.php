<?php
class cTransaction
{
    /**
     * @var string
     */
    public string $id;
    
    /**
     * @var int
     */
    public int $timestamp;
       
    /**
     * @var cTxIn[]
     */
    public array $txIns = [];
    
    /**
     * @var cTxOut[]
     */
    public array $txOuts = [];
}