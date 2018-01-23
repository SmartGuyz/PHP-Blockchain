<?php
$rCurl = curl_init();

curl_setopt($rCurl, CURLOPT_URL,"http://phpbc.sourcexs.nl:3001/mineBlock");
curl_setopt($rCurl, CURLOPT_POST, true);
curl_setopt($rCurl, CURLOPT_POSTFIELDS, "data=test");
curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);

$sOutput = curl_exec($rCurl);

print_r($sOutput);

curl_close ($rCurl);
?>