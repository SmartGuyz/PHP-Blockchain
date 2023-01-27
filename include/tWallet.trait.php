<?php
use Underscore\Underscore as _;

trait tWallet
{
    private $rWalletFile;

	/**
	 * @throws Exception
	 */
	private function initWallet(): void
    {
        // Let's not overwrite an existing private key
        if(file_exists($this->aIniValues['database']['datafile_wallet']))
        {
            return;
        }
        
        $sNewPrivateKey = $this->generatePrivateKey();
        file_put_contents(__DIR__."/{$this->aIniValues['database']['datafile_wallet']}", $sNewPrivateKey, LOCK_EX);
        
        self::debug("new wallet with private key created to: {$this->aIniValues['database']['datafile_wallet']}");
    }
    
    private function generatePrivateKey(): string
    {
        $oKeyPair = $this->cEC->genKeyPair();
        $oPrivateKey = $oKeyPair->getPrivate();
        
        return (string)$oPrivateKey->toString(16);
    }
    
    private function getPrivateFromWallet(): string
    {
        $sBuffer = file_get_contents(__DIR__."/{$this->aIniValues['database']['datafile_wallet']}");
        return (string)$sBuffer;
    }
    
    public function getPublicFromWallet(): string
    {
        $sPrivateKey = $this->getPrivateFromWallet();
        $oKey = $this->cEC->keyFromPrivate($sPrivateKey, 'hex');
        return $oKey->getPublic(false, 'hex');
    }
    
    public function getAccountBalance(): int
	{
        return $this->getBalance($this->getPublicFromWallet(), $this->getUnspentTxOuts());
    }
    
    public function getBalance(string $sAddress, array $aUnspentTxOut): int
    {
        return array_reduce($this->findUnspentTxOuts($sAddress, $aUnspentTxOut), function($iAmount, $oUnspentTxOut) { return $iAmount + $oUnspentTxOut->amount; }, 0);
    }
    
    private function findUnspentTxOuts(string $sOwnerAddress, array $aUnspentTxOut): array
	{
        return array_filter($aUnspentTxOut, function($oUnspentTxOut) use($sOwnerAddress) { return ($oUnspentTxOut->address == $sOwnerAddress); });
    }

	/**
	 * @throws Exception
	 */
	private function findTxOutsAmount(int $iAmount, array $aMyUnspentTxOuts): array
	{
        $iCurrentAmount = 0;
        $aIncludedUnspentTxOuts = [];
        
        foreach($aMyUnspentTxOuts AS $oMyUnpspentTxOut)
        {
            $aIncludedUnspentTxOuts[] = $oMyUnpspentTxOut;
            $iCurrentAmount           += $oMyUnpspentTxOut->amount;
            if($iCurrentAmount >= $iAmount)
            {
                $iLeftOverAmount = $iCurrentAmount - $iAmount;
                return [$aIncludedUnspentTxOuts, $iLeftOverAmount];
            }
        }
        throw new Exception("Cannot create transaction from the available unspent transaction outputs. Required amount: {$iAmount}. Available unspentTxOuts: ".json_encode($aMyUnspentTxOuts));
    }
    
    private function createTxOuts(string $sReceiverAddress, string $sMyAddress, int $iAmount, int $iLeftOverAmount, stdClass $oDataObject): array
	{
        $oTxOut1 = new cTxOut($sReceiverAddress, $iAmount, $oDataObject);
        if($iLeftOverAmount === 0)
        {
            return [$oTxOut1];
        }
        else 
        {
            $oLeftOverTx = new cTxOut($sMyAddress, $iLeftOverAmount, new stdClass());
            return [$oTxOut1, $oLeftOverTx];
        }
    }
    
    private function filterTxPoolTxs(array $aUnspentTxOuts, array $aTransactionPool): array
	{
        $aTxIns = [];
        $aTxMap = array_map(function(cTransaction $oTransaction) { return $oTransaction->txIns; }, $aTransactionPool);
        array_walk_recursive($aTxMap, function($v) use(&$aTxIns) { $aTxIns[] = $v; });

        $aRemoveable = [];
        foreach($aUnspentTxOuts AS $oUnspentOut)
        {
            $aTxIn = _::find($aTxIns, function(cTxIn $oTxIn) use($oUnspentOut) { return ($oTxIn->txOutIndex === $oUnspentOut->txOutIndex && $oTxIn->txOutId === $oUnspentOut->txOutId); });
            if(count($aTxIn) === 0)
            {
            }
            else
            {
                $aRemoveable[] = $oUnspentOut;
            }
        }
        
        $compareObjects = function($obj_a, $obj_b) {
            return (($obj_a->txOutId == $obj_b->txOutId) ? 0 : -1);
        };
        
        return array_udiff($aUnspentTxOuts, $aRemoveable, $compareObjects);
    }

	/**
	 * @throws ErrorException
	 * @throws Exception
	 */
	private function createTransaction(string $sReceiverAddress, int $iAmount, stdClass $oDataObject , string $sPrivateKey, array $aUnspentTxOut, array $aTxPool): cTransaction
    {
        $sMyAddress = $this->getPublicKey($sPrivateKey);
        $aMyUnspentTxOutsA = array_filter($aUnspentTxOut, function(cUnspentTxOut $oUTxO) use($sMyAddress) { return ($oUTxO->address === $sMyAddress); });

        $aMyUnspentTxOuts = $this->filterTxPoolTxs($aMyUnspentTxOutsA, $aTxPool);

        [$aIncludedUnspentTxOuts, $iLeftOverAmount] = $this->findTxOutsAmount($iAmount, $aMyUnspentTxOuts);
        
        $toUnsignedTxIn = function(cUnspentTxOut $oUnspentTxOut) 
        {
            $cTxIn = new cTxIn();
            $cTxIn->txOutId = $oUnspentTxOut->txOutId;
            $cTxIn->txOutIndex = $oUnspentTxOut->txOutIndex;
            return $cTxIn;
        };
        
        $aUnsignedTxIns = array_map($toUnsignedTxIn, $aIncludedUnspentTxOuts);
        
        $cTransaction = new cTransaction();
        $cTransaction->txIns = $aUnsignedTxIns;
        $cTransaction->txOuts = $this->createTxOuts($sReceiverAddress, $sMyAddress, $iAmount, $iLeftOverAmount, $oDataObject);        
        $cTransaction->timestamp = $this->getCurrentTimestamp();
        $cTransaction->id = $this->getTransactionId($cTransaction);
        $cTransaction->txIns = array_map(function(cTxIn $oTxIns, $iKey) use($cTransaction, $sPrivateKey, $aUnspentTxOut) { $oTxIns->signature = $this->signTxIn($cTransaction, $iKey, $sPrivateKey, $aUnspentTxOut); return $oTxIns; }, $cTransaction->txIns, array_keys($cTransaction->txIns));
        
        return $cTransaction;
    }
}