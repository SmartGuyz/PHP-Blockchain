<?php
$aPostFiels['data'] = ['address' => '83.149.109.148', 'port' => 6001];

$rCurl = curl_init();

curl_setopt($rCurl, CURLOPT_URL,"http://188.227.207.70:3001/addPeer");
curl_setopt($rCurl, CURLOPT_POST, true);
curl_setopt($rCurl, CURLOPT_POSTFIELDS, json_encode($aPostFiels));
curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);

$sOutput = curl_exec($rCurl);

print_r($sOutput);

curl_close ($rCurl);
?>