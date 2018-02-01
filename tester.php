<?php
//$aPostFiels['data'] = "test";

$rCurl = curl_init();

curl_setopt($rCurl, CURLOPT_URL,"http://phpbc.sourcexs.nl:3001/address");
curl_setopt($rCurl, CURLOPT_POST, false);
//curl_setopt($rCurl, CURLOPT_POSTFIELDS, json_encode($aPostFiels));
curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);

$sOutput = curl_exec($rCurl);

print_r($sOutput);

curl_close ($rCurl);
?>