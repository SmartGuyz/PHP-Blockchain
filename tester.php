<?php
$oData = new stdClass();
$oData->index       = 0;
$oData->hash        = "";
$oData->prevHash    = "";
$oData->timestamp   = 0;
$oData->data        = "";
$oData->difficulty  = 1;
$oData->nonce       = 1;

$aBlockchain[] = $oData;

$oData = new stdClass();
$oData->index       = 0;
$oData->hash        = "";
$oData->prevHash    = "";
$oData->timestamp   = 0;
$oData->data        = "";
$oData->difficulty  = 1;
$oData->nonce       = 1;

$aBlockchain[] = $oData;

$iTotal = 0;
foreach(array_column($aBlockchain, 'difficulty') AS $iValue)
{
    $iTotal += pow(2, $iValue);
}

echo $iTotal;
?>