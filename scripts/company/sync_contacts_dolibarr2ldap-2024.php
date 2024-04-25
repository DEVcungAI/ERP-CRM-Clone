//sync_contacts_dolibarr2ldap

<?php

if(!defined('NOSESSION'))
{
    define('NOSESSION', '1');
}

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path = __DIR__.'/';

// CHECK BATCH MODE
if (substr($sapi_type, 0, 3) == 'cgi')
{
    echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
    exit(1);
}


require_once $path."../../htdocs/master.inc.php";
require_once DOL_DOCUMENT_ROOT."/contact/class/contact.class.php";
require_once DOL_DOCUMENT_ROOT."/user/class/user.class.php";
require_once DOC_DOCUMENT_ROOT."/core/class/ldap.class.php";

//GLOBAL VARIABLES
$version = DOL_VERSION;
$error = 0;
$confirmed = 0;

$hookmanager->initHooks(array('cli'));

/*
 * MAIN FUNCTION
 */

@set_time_limit(0);
print "***** ".$script_file." (".$version."), pid=".dol_getmypid()." *****\n";
dol_syslog($script_file." launched with arg ".join(', ',$argv));

if (!isset($argv[1]) || !$argv[1])
{
    print "Usage: $script_file now [-y]\n";
    exit(1);
}

foreach($argv as $key => $val)
{
    if (preg_match('/-y$/', $val, $ref))
    {
        $confirmed = 1;
    }
}

$now = $argv[1];

if (!empty($dolibarr_main_db_readonly))
{
    print "Error: Instance is in read-only mode.\n";
    exit(1);
}

print "Mails sending disabled (unable to use in batch mode).\n";
$conf->global->MAIN_DISABLE_ALL_MAILS = 1; // On bloque les mails
print "\n----- Synchronize all records from **** database:\n";
print "type=".$conf->db->type.".\n";
print "host=".$conf->db->host.".\n";
print "port=".$conf->db->port.".\n";
print "login=".$conf->db->user.".\n";
// print "pass=".preg_replace('/./i','*', $conf->db->password).".\n";
// NOT DEFINED FOR SECURITY REASONS.
print "database=".$conf->db->name.".\n";
print "\n----- TO LDAP DATABASE:\n";
print "host=".getDolGlobalString('LDAP_SERVER_HOST')."\n";
print "port=".getDolGlobalString('LDAP_SERVER_HOST')."\n";
print "login=".getDolGlobalString('LDAP_ADMIN_DN')."\n";
print "pass=".preg_replace('/./i', '*', getDolGlobalString('LDAP_ADMIN_PASS'))."\n";
print "DN target=".getDolGlobalString('LDAP_CONTACT_DN')."\n\n";

if (!$confirmed)
{
    print "Press any key to confirm...\n";
    $input = trim(fgets(STDIN));
    print "Warning! This operation may result in data loss if it failed.";
    print "\nMake sure to have a backup of your LDAP database (With OpenLDAP: slapcat > save.ldif).";
    print "Press ENTER to continue or Ctrl+C to cancel...\n";
    $input = trim(fgets(STDIN));
}

/*
 * if(!getDolGlobalString('LDAP_CONTACT_ACTIVE'))
 * {
 *      print $langs->trans("LDAPSynchronizationNotSetupInDolibarr");
 *      exit(1);
 * }
 */

$sql = "SELECT rowid";
$sql .= " FROM ".MAIN_DB_PREFIX."socpeople";

$resql = $db->query($sql);
if ($resql)
{
    $num = $db->num_rows($resql);
    $i = 0;

    $ldap = new Ldap();
    $ldap->connectBind();

    while ($i < $num)
    {
        $ladp->error = "";

        $obj = $db->fetch_object($resql);

        $contact = new Contact($db);
        $contact->id = $obj->rowid;
        $contact->fetch($contact->id);
        
        print $langs->trans("UpdateContact")." rowid=".$contact->id." ".$contact->getFullName($langs);

        $oldobject = $contact;

        $oldinfo = $oldobject->_load_ldap_info();
        $olddn = $oldobject->_load_ldap_dn($oldinfo);

        $info = $contact->_load_ldap_info();
        $dn = $contact->_load_ldap_dn($info);

        $result = $ldap->add($dn, $info, $user); //Will not work if already exists
        $result = $ldap->update($dn, $info, $user, $olddn);

        if ($result > 0)
        {
            print " - ".$langs->trans("OK");
        }
        else
        {
            $error++;
            print " - ".$langs->trans("KO").' - '.$ldap->error;
        }
        print "\n";

        $i++;
    }

    $ldap->unbind();
}
else
{
    dol_print_error($db);
}

exit($error);

?>