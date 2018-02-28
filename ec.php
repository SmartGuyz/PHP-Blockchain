<?php
require_once "vendor/autoload.php";

$cEC = new \Elliptic\EC('secp256k1');

/*$sPrivateKey = "3d67dad91c7d9ba45a0d93487eaf9a4324488ea57321a777dfc23d62e7a3deb8";
$oKey = $cEC->keyFromPrivate($sPrivateKey, 'hex');
$sPublicKey = $oKey->getPublic(false, 'hex');

$sData = "ab4c3451";
$oSignature = $oKey->sign($sData);

$sDerSign = $oSignature->toDER('hex');

echo "Verified: ".(($oKey->verify($sData, $sDerSign) == true) ? "true" : "false")." - {$sPublicKey}\n";

unset($sPrivateKey);*/

$sData = "ab4c3451";
$sDerSign = "304402203e69585e81e5097580452e970b512c1cec5311470b5747ff1e88f91252f2561802200d7160f2e3b387552b7e60cd516b2e5bad3b91b5aae7a39946e9f8e751f89020";
$sPublicKey = "0414623009a7fc115efb52affe75455cdd1818853b21e5ced98dac6acede6332b75aec7b6a546ebc3703cc9a4df12bce774ea52308418d7686d2f68bb82e91bf3a";

$oKey = $cEC->keyFromPublic($sPublicKey, 'hex');

// Verify signature
echo "Verified: ".(($oKey->verify($sData, $sDerSign) == true) ? "true" : "false")."\n";
?>