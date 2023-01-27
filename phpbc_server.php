#!/usr/local/bin/php -q
<?php
error_reporting(E_ALL);
ini_set('display_errors', true);
date_default_timezone_set('Europe/Amsterdam');
set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
ini_set('implicit_flush', true);
ob_implicit_flush(true);

require_once(__DIR__."/include/Loader.php");

if(!isset($_SERVER['argv'][1]) OR !in_array($_SERVER['argv'][1], $aCommands))
{
    die(" * Usage: ./{$sName} {start|stop|status}\n");
}
elseif($_SERVER['argv'][1] == "start")
{   
    if(file_exists($sLockFile))
    {
        die(" * {$sName} can't create pid-lock, the file exists at {$sLockFile}\n * It looks like {$sName} is allready running!\n");
    }
    else
    {
        // Load and start blockchain
		try
		{
			$cBlockchain = new cBlockchain($cSQLiteBC, $aIniValues);
		}
		catch(Exception $e)
		{
            throw new Exception($e->getMessage());
		}

		$aPid = [];
        for($i = 0; $i < 1; $i++)
        {
            $aPid[$i] = pcntl_fork();
            if($aPid[$i] == -1)			// Whoops, error ;-)
            {
                die(" * Whoops, could not fork, this is not good!\n");
            }
            elseif($aPid[$i])			// Parent must die when all childs are here ;-)
            {
                if($i == 0)
                {
                    die(" * {$sName} has been started succesfully!\n");
                }
            }
            else			// Child proccess
            {	
                // Set lockfile
                $rHandle = fopen($sLockFile, "a");
                fprintf($rHandle, "%s\n", posix_getpid());
                fclose($rHandle);
                
                echo " * Forked child with pid ".posix_getpid()."\n";
                
                // Start server
				try
				{
					(new cHttpServer($aConfig))->run($cBlockchain);
				}
				catch(Exception $e)
				{
					throw new Exception($e->getMessage());
				}
			}
        }
    }
}
elseif($_SERVER['argv'][1] == "stop")
{   
    if(file_exists($sLockFile))
    {
        $sData = file_get_contents($sLockFile);
        $aData = explode("\n", $sData);
        foreach($aData AS $iKey => $iPid)
        {
            if(is_numeric($iPid))
            {               
                posix_kill($iPid, 9);
                echo " * Child #{$iKey} killed pid {$iPid}...\n";
            }
            else
            {
                echo " * {$sName} stopped all running proccesses now!\n";
            }
        }
        unlink($sLockFile);
    }
    else
    {
        die(" * Euhm, {$sName} is not running, what do you want me to stop?\n");
    }
}
elseif($_SERVER['argv'][1] == "status")
{
    if(file_exists($sLockFile))
    {
        die(" * {$sName} is up and running right now...\n");
    }
    else
    {
        die(" * {$sName} is not running right now...\n");
    }
}