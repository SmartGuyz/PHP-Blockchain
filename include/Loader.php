<?php
error_reporting(E_ALL);
if(!ini_get('display_errors'))
{
    ini_set('display_errors', true);
}

$sName = "PHPBC Node";
$sLockFile = "/tmp/".strtolower($sName).".pid.lock";

if(!function_exists('pcntl_fork'))
{
    die(" * PCNTL functions are not available on this PHP installation, {$sName} won't run without PCNTL!\n");
}

setlocale(LC_TIME, "nl_NL");
date_default_timezone_set("Europe/Amsterdam");

header('Content-Type: text/html; charset=UTF-8');
header("Last-modified: ".gmstrftime("%a, %d %b %Y %T %Z",getlastmod()));

$sConfig = __DIR__."/../config.ini";
if(file_exists($sConfig))
{
    $aIniValues = @parse_ini_file($sConfig, true);
    
    if(!isset($aIniValues['database']['datafile_blockchain']))
    {
        die("The ini file is corrupt, variable \"datafile_blockchain\" is missing in \"database\"");
    }
    elseif(!isset($aIniValues['database']['datafile_peers']))
    {
        die("The ini file is corrupt, variable \"datafile_peers\" is missing in \"database\"");
    }
    elseif(!isset($aIniValues['server']['http_address']))
    {
        die("The ini file is corrupt, variable \"http_address\" is missing in \"server\"");
    }
    elseif(!isset($aIniValues['server']['http_port']))
    {
        die("The ini file is corrupt, variable \"http_port\" is missing in \"server\"");
    }
    elseif(!isset($aIniValues['server']['p2p_address']))
    {
        die("The ini file is corrupt, variable \"p2p_address\" is missing in \"server\"");
    }
    elseif(!isset($aIniValues['server']['p2p_port']))
    {
        die("The ini file is corrupt, variable \"p2p_port\" is missing in \"server\"");
    }
}
else
{
    die("There is no configuration file (config.ini) in the etc directory (yet)");
}

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

// Open/Create SQLite DB for the blockchain
$cSQLiteBC = new SQLite3($aIniValues['database']['datafile_blockchain']);

// Check tables
$oSqlBC = $cSQLiteBC->query("SELECT `name` FROM `sqlite_master` WHERE `type` = 'table' AND `name` = 'blockchain'");
if(!$oSqlBC->fetchArray())
{
    $sQuery = "CREATE TABLE `blockchain` (";
    $sQuery .= "`index` INTEGER PRIMARY KEY,";
    $sQuery .= "`hash` CHAR(64) NOT NULL,";
    $sQuery .= "`prevHash` CHAR(64) NOT NULL,";
    $sQuery .= "`timestamp` INTEGER NOT NULL,";
    $sQuery .= "`data` TEXT NOT NULL,";
    $sQuery .= "`difficulty` INTEGER NOT NULL,";
    $sQuery .= "`nonce` INTEGER NOT NULL";
    $sQuery .= ")";
    
    if(!$cSQLiteBC->exec($sQuery))
    {
        die("Database table 'blockchain' can not be created!");
    }
}

// HTTP server
$aConfig[0]['name'] 	= "http";
$aConfig[0]['ip'] 	    = $aIniValues['server']['http_address'];
$aConfig[0]['port'] 	= $aIniValues['server']['http_port'];
$aConfig[1]['name'] 	= "p2p";
$aConfig[1]['ip'] 	    = $aIniValues['server']['p2p_address'];
$aConfig[1]['port'] 	= $aIniValues['server']['p2p_port'];

// Start commands
$aCommands[] = "start";
$aCommands[] = "stop";
$aCommands[] = "status";
?>