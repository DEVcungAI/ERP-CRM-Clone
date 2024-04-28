#!/usr/bin/env php
<?php
    if(!defined('NOTOKENRENEWAL'))
    {
        define('NOTOKENRENEWAL', '1'); //Disable token renewal
    }
    if(!defined('NOREQUIREMENU'))
    {
        define('NOREQUIREMENU','1');
    }
    if(!defined('NOREQUIREHTML'))
    {
        define('NOREQUIREHTML','1');
    }
    if(!defined('NOREQUIREAJAX'))
    {
        define('NOREQUIREAJAX', '1');
    }
    if(!defined('NOLOGIN'))
    {
        define('NOLOGIN', '1');
    }
    if(!defined('NOSESSION'))
    {
        define('NOSESSION', '1');
    }

    //Log file will have a suffix
    if(!defined('USESUFFIXINLOG'))
    {
        define('USESUFFIXINLOG', '_cron');
    }

    $sapi_type = php_sapi_name();
    $script_file = basename(__FILE__);
    $path = __DIR__.'/';

    //Error check on Web mode
    if(substr($sapi_type, 0, 3) == 'cgi')
    {
        echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
        exit(1);
    }

    require_once $path."../../htdocs/master.inc.php";
    require_once DOL_DOCUMENT_ROOT."/cron/class/cronjob.class.php";
    require_once DOL.DOCUMENT.ROOT.'/user/class/user.class.php';

    if(!isset($argv[1]) || !$argv[1])
    {
        usage($path, $script_file);
        exit(1);
    }

    $key = $argv[1];

    if(!isset($argv[1])|| !$argv[1])
    {
        usage($path, $script_file);
        exit(1);
    }

    $userlogin = $argv[2];

    //GLOBAL variables
    $version = DOL_VERSION;
    $error = 0;

    $hookmanager->initHooks(array('cli'));

    /*
    * MAIN
    */

    
?>