<?php
error_reporting(E_ALL);
if(!ini_get('display_errors'))
{
    ini_set('display_errors', true);
}

$sName = "PHPBC Node";
$sLockFile = "/tmp/".strtolower($sName).".pid.lock";

// HTTP server
$aCmd[0]['name'] 	= "cHttpServer";
$aCmd[0]['ip'] 	    = "0.0.0.0";
$aCmd[0]['port'] 	= 3001;

// P2P server
$aCmd[1]['name'] 	= "cP2PServer";
$aCmd[1]['ip'] 	    = "0.0.0.0";
$aCmd[1]['port'] 	= 6001;

// Start commands
$aCommands[] = "start";
$aCommands[] = "stop";
$aCommands[] = "status";

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
        die("The ini file is corrupt, variable \"sqlite_datafile\" is missing");
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

// Open/Create SQLite DB
$cSQLite = new SQLite3($aIniValues['database']['datafile_blockchain']);

// Check tables
$oSqlBC = $cSQLite->query("SELECT `name` FROM `sqlite_master` WHERE `type` = 'table' AND `name` = 'blockchain'");
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
    
    if(!$cSQLite->exec($sQuery))
    {
        die("Database table 'blockchain' can not be created!");
    }
}

$oSqlP2P = $cSQLite->query("SELECT `name` FROM `sqlite_master` WHERE `type` = 'table' AND `name` = 'peers'");
if(!$oSqlP2P->fetchArray())
{
    $sQuery = "CREATE TABLE `peers` (";
    $sQuery .= "`id` INTEGER PRIMARY KEY AUTOINCREMENT,";
    $sQuery .= "`remote_address` CHAR(38) NOT NULL,";
    $sQuery .= "`remote_port` INTEGER NOT NULL";
    $sQuery .= ")";
    
    $cSQLite->exec($sQuery);
}
?>