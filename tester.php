<?php
$oData = new stdClass();
$oData->vdm = 12345678;
$oData->relation = 12345678;
$oData->blaat = "tester";


$aPostFiels['data'] = ["toAddress" => "04cc783e477377d94d8af7bdea83f9a62615fa5e8401d4f195ee4a1c04ff7ff992505d42a1d978d92b56aaa8e21cd1ee2e08a18644f5889fbc43e3b91774aa8d99", "dataObject" => serialize($oData)];

$rCurl = curl_init();

curl_setopt($rCurl, CURLOPT_URL,"http://phpbc.sourcexs.nl:3001/sendTransaction");
curl_setopt($rCurl, CURLOPT_POST, false);
curl_setopt($rCurl, CURLOPT_POSTFIELDS, json_encode($aPostFiels));
curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);

$sOutput = curl_exec($rCurl);

print_r($sOutput);

curl_close ($rCurl);
?>