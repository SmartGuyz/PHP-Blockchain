<?php
error_reporting(E_ALL);
if(!ini_get('display_errors'))
{
    ini_set('display_errors', true);
}

setlocale(LC_TIME, "nl_NL");
date_default_timezone_set("Europe/Amsterdam");

header('Content-Type: text/html; charset=UTF-8');
header("Last-modified: ".gmstrftime("%a, %d %b %Y %T %Z",getlastmod()));

function default_autoloader($sClassName)
{
	$sPath = dirname( __FILE__ ).'/';
	$sClassName = ltrim($sClassName, '\\');
	$sFileName = '';
	$sNamespace = '';
	$iLastNsPos = strrpos($sClassName, '\\');
	
	if($iLastNsPos !== false)
	{
		$sNamespace = substr($sClassName, 0, $iLastNsPos);
		$sClassName = substr($sClassName, $iLastNsPos + 1);
		$sFileName = str_replace('\\', DIRECTORY_SEPARATOR, $sNamespace).DIRECTORY_SEPARATOR;
	}

    switch(substr($sClassName, 0, 1))
    {
        case 'c':
        	$sFileName .= str_replace('_', DIRECTORY_SEPARATOR, $sClassName).'.class.php';
            break;
        case 't':
        	$sFileName .= str_replace('_', DIRECTORY_SEPARATOR, $sClassName).'.trait.php';
            break;
        default:
        	$sFileName .= str_replace('_', DIRECTORY_SEPARATOR, $sClassName).'.class.php';
            break;
    }
    
    if(@file_exists($sPath.$sFileName))
    {
    	require $sPath.$sFileName;
    }
    else
    {
    	throw new Exception("{$sClassName} does not exitst in our system! ({$sPath}{$sFileName})");
    }
}

spl_autoload_register('default_autoloader');

$cBlockchain = new cBlockchain();
?>