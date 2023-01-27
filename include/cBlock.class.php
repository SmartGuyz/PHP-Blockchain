<?php
class cBlock
{
	public array
		$data;
	public string
		$prevHash,
		$hash;
	public int
		$index,
		$timestamp,
		$nonce,
		$difficulty,
		$version;

	public function __construct(int $iIndex, string $sHash, string $sPrevHash, int $iTimestamp, array $aTransactions, int $iDifficulty, int $iNonce, int $iVersion = 2)
    {
        $this->index       = $iIndex;
        $this->hash        = $sHash;
        $this->prevHash    = $sPrevHash;
        $this->timestamp   = $iTimestamp;
        $this->data        = $aTransactions;
        $this->difficulty  = $iDifficulty;
        $this->nonce       = $iNonce;
        $this->version     = $iVersion;
    }
}