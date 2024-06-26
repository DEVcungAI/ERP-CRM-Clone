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

    // CURRENT DATE
    $now = dol_now();

    @set_time_limit(0);
    print "***** ".$script_file." (".$version."), pid=".dol_getmypid()." - userlogin=".$userlogin." - ".dol_print_date($now, ' dayhourrfc')." - ".gethostname()." *****\n";

    //CHECK MODULE CRON IS ACTIVATED
    if (!isModEnabled('cron'))
    {
        print "Error: Module Scheduled jobs (cron) not activated.\n";
        exit(1);
    }

    //CHECK SECURITY KEY
    if ($key != getDolGlobalString('CRON_KEY'))
    {
        print "Error: securitykey is incorrect.\n";
        exit(1);
    }

    if(!empty($dolibarr_main_db_readonly))
    {
        print "Error: Current instance is in read-only mode.\n";
        exit(1);
    }

    //If parameter userlogin contains 'firstadmin'
    if ($userlogin == 'firstadmin')
    {
        $sql = 'SELECT login, entity from '.MAIN_DB_PREFIX.'user WHERE admin = 1 and status = 1 ORDER BY entity LIMIT 1';
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj)
            {
                $userlogin = $obj->login;
                echo "First admin user found is login '".$userlogin."', entity ".$obj->entity."\n";
            }
        }
        else
        {
            dol_print_error($db);
        }
    }

    //CHECK USER LOGIN
    $user = new User($db);
    $result = $user->fetch('', $userlogin, '', 1);
    if ($result < 0)
    {
        echo "User Error: ".$user->error;
        dol_syslog("cron_run_jobs.php:: User Error:".$user->error, LOG_ERR);
        exit(1);
    }
    else
    {
        if (empty($user->id))
        {
            echo "User ".$userlogin." does not exists.\n";
            dol_syslog("User ".$userlogin." does not exists.\n", LOG_ERR);
            exit(1);
        }
    }

    //RELOAD LANGS
    $langcode = getDolGlobalString('MAIN_LANG_DEFAULT', 'auto');
    if (!empty($user->conf->MAIN_LANG_DEFAULT))
    {
        $langcode = $user->conf->MAIN_LANG_DEFAULT;
    }
    if ($langs->getDefaultLang() != $langcode)
    {
        $langs->setDefaultLang($langcode);
        $langs->tab_translate = array();
    }

    //LANGUAGES MANAGEMENT
    $langs->loadLangs(array('main', 'admin', 'cron', 'dict'));
    
    $user->getrights();

    if(isset($argv[3]) && $argv[3])
    {
        $id = $argv[3];
    }

    $forcequalified = 0;

    if (isset($argv[4]) && $argv[4] == '--force')
    {
       $forcequalified = 1;
    }

    //CREATE A JOB OBJECT
    $object = new Cronjob($db);

    $filter = array();
    if (!empty($id))
    {
        if (!is_numeric($id))
        {
            echo "Error: Bad value for Job ID parameter.\n";
            dol_syslog("cron_run_jobs.php, Bad value for Job ID parameter.", LOG_WARNING);
            exit(2);
        }
        $filter['t.rowid'] = $id;
    }

    $result = $object->fetchAll('ASC, ASC, ASC', 't.priority, t.entity, t.rowid', 0, 0, 1, $filter, 0);
    if ($result < 0)
    {
        echo "Error: ".$object->error;
        dol_syslog("cron_run_jobs.php fetch error: ".$object->error.".\n", LOG_ERR);
        exit(1);
    }

    /** TODO DUPLICATE CODE. THIS SEQUENCE OF CODE MUST BE SHARED WITH CODE INTO
     ** public/cron/cron_run_jobs.php php page.
    */

    $nbofjobs = count($object->lines);
    $nbofjobslaunchedok = 0;
    $nbofjobslaunchedko = 0;

    if (is_array($object->lines) && (count($object->lines) > 0))
    {
        $savconf = dol_clone($conf);

        //LOOP OVER JOB
        foreach($object->lines as $line)
        {
            dol_syslog("cron_run_jobs.php cronjobid: ".$line->id." priority= ".$line->priority." entity= ".$line->entity." label=".$line->label, LOG_DEBUG);

            echo "cron_run_jobs.php cronjobid: ".$line->id.", priority=".$line->priority.", entity=".$line->entity.", label=".$line->label;

                        
            //FORCE RELOAD OF SETUP FOR THE CURRENT ENTITY
            if((empty($line->entity) ? 1 : $line->entity) != $conf->entity)
            {
                dol_syslog("cron_run_jobs.php: We work on another entity conf than ".$conf->entity.", so we reload mysoc, langs, user and conf.\n", LOG_DEBUG);
                echo " -> We change entity so we reload mysoc, langs, user and conf.\n";

                $conf->entity = (empty($line->entity) ? 1 : $line->entity);
                
                //THIS MAKE ALSO THE $mc->setValues($conf);
                //THAT RELOAD $mc->sharings
                $conf->setValues($db);

                $mysoc->setMysoc($conf);
                
                //FORCE RECHECK THAT USER IS OK FOR THE ENTITY TO PROCESS
                //RELOAD PERMISSION FOR ENTITY
                if ($conf->entity != $user->entity)
                {
                    $result = $user->fetch('', $userlogin, '', 1);

                    if ($result < 0)
                    {
                        echo "\nUser error: ".$user->error.".\n";
                        dol_syslog("cron_run_jobs.php: User error: ".$user->error, LOG_ERR);
                        exit(1);
                    }
                    else
                    {
                        if ($result == 0)
                        {
                            echo "\nUser login: ".$userlogin." does not exist for entity ".$conf->entity.".\n";

                            dol_syslog("cron_run_jobs.php: User: ".$userlogin." does not exist", LOG_ERR);
                            exit(1);
                        }
                    }
                    $user->getrights();
                }

                //RELOAD LANGS
                $langcode = getDolGlobalString('MAIN_LANG_DEFAULT', 'auto');
                
                if(!empty($user->conf->MAIN_LANG_DEFAULT))
                {
                    $langcode = $user->conf->MAIN_LANG_DEFAULT;
                }

                if ($langs->getDefaultLang() != $langcode)
                {
                    $langs->setDefaultLang($langcode);
                    $langs->tab_translate = array();
                    $langs->loadLangs(array('main', 'admin', 'cron', 'dict'));
                }
            }

            if (!verifCond($line->test))
            {
                continue;
            }

            //IF data_next_jobs IS LESS OF CURRENT DATE, EXECUTE THE PROGRAM, AND STORE THE EXECUTION TIME OF THE NEXT EXECUTION IN DATABASE
            if ($forcequalified || (($line->datenextrun < $now) && (empty($line->datestart) || $line->datestart <= $now) && (empty($line-dateend) || ($line->dateend >= $now))))
            {
                echo " - qualified";

                dol_syslog("cron_run_jobs.php line->datenextrun: ".dol_print_date($line->datenextrun, 'dayhourrfc')." line->datestart: ".dol_print_date($line->datestart, 'dayhourrfc')." line->dateend: ".dol_print_date($line->dateend, 'dayhourrfc')." now: ".dol_print_date($now, 'dayhourrfc'));

                $cronhob = new Cronjob($db);

                $result = $cronjob->fetch($line->id);

                if ($result < 0)
                {
                    echo " - Error cronjobid: ".$line->id." cronjob->fetch: "$cronjob->error."\n";
                    echo "Failed to fetch job ".$line->id."\n";
                    dol_syslog("cron_run_jobs.php::fetch Error ".$cronjob->error, LOG_ERR);
                    exit(1);
                }


                //EXECUTE JOB
                $result = $cronjob->run_jobs($userlogin);

                if ($result < 0)
                {
                    echo " - Error cronjobid: ".$line->id." cronjob->run_job: ".$cronjob->error."\n";

                    echo "At least one job failed. Go on menu Home-Setup-Admin tools to see result for each job.\n";
                    echo "You can also enable module LOG if not yet enabled, run again and take a look into dolibarr.log file.\n";

                    dol_syslog("cron_run_jobs.php::run_jobs Error ".$cronjob->error, LOG_ERR);
                    $nbofjobslaunchedko++;
                    $resultstring = 'KO.';
                }
                else
                {
                    $nbofjobslaunchedok++;
                    $resultstring = 'OK.';
                }

                echo " - run_jobs ".$resultstring." result = ".$result;

                //RE-PROGRAM THE NEXT EXECUTION AND STORES THE LAST EXECUTION TIME FOR THIS JOB.
                $result = $cronjob->reprogram_jobs($userlogin, $now);
                if ($result < 0)
                {
                    echo " - Error cronjobid: ".$line->id." cronjob->reprogram_jobs: ".$cronjob->error.".\n";
                    echo "Enable module LOG if not yet enabled, run again and take a look into dolibarr.log file.\n";
                    dol_syslog("cron_run_jobs.php::reprogram_jobs Error ".$cronjob->error, LOG_ERR);
                    exit(1);
                }

                echo " - reprogrammed.\n";
            }
            else
            {
                echo " - not qualified.\n";

                dol_syslog("cron_run_jobs.php job not qualified line->datenextrun: ".dol_print_date($line->datenextrun, 'dayhourrfc')." line->datestart: ".dol_print_date($line->datestart, 'dayhourrfc')." line->dateend: ".dol_print_date($line->dateend, 'dayhourrfc')." now: ".dol_print_date($now, 'dayhourrfc'));
            }
        }

        $conf = $savconf;
    }
    else
    {
        echo "cron_run_jobs.php, no qualified job found.\n";
    }
    
    $db->close();

    if ($nbofjobslaunchedko)
    {
        exit(1);
    }
    exit(0);

    /**
     * SCRIPT CRON USAGE
     * 
     * @param string $path          PATH
     * @param string $script_file   Filename
     * @return void
     */

    function usage($path, $script_file)
    {
        print "Usage: ".$script_file." securitykey userlogin|'firstadmin' [cronjobid] [--force]\n";
        print "The script return 0 when everything worked successfully.\n";
        print "\nOn Linux system, you can have cron jobs ran automatically by adding an entry into cron.\n";
        print "For example, to run pending tasks each day at 3:30, you can add this line:\n";
        print "30 3 * * * ".$path.$script_file." securitykey userlogin > ".DOL_DATA_ROOT."/".$script_file.".log.\n";
        print "For example, to run pending tasks every 5m, you can add this line:\n";
        print "*/5 * * * * ".$path.$script_file." securitykey userlogin > ".DOL_DATA_ROOT."/".$script_file.".log.\n";
        print "\nThe option --force allow to bypass the check on date of execution so job will be executed even if date is not yet reached.\n";
    }

?>