<?php

## Check https://docs.observium.org/config_options/ for documentation of possible settings

## It's recommended that settings are edited in the web interface at /settings/ on your observium installation.
## Authentication and Database settings must be hardcoded here because they need to work before you can reach the web-based configuration interface

$config['db_host']                   = 'localhost';
$config['db_socket']                 = '/var/run/mysql/mysql.sock';
$config['db_name']                   = 'observium';
$config['db_user']                   = 'observium';
$config['db_pass']                   = '<db password>';

$config['base_url']                  = 'https://<your FQDN>';

$config['rrdtool']                   = "/usr/local/bin/rrdtool";
$config['fping']                     = "/usr/local/sbin/fping";
$config['fping6']                    = "/usr/local/sbin/fping6";
$config['snmpwalk']                  = "/usr/local/bin/snmpwalk";
$config['snmpget']                   = "/usr/local/bin/snmpget";
$config['snmpgetnext']               = "/usr/local/bin/snmpgetnext";
$config['snmpbulkget']               = "/usr/local/bin/snmpbulkget";
$config['snmpbulkwalk']              = "/usr/local/bin/snmpbulkwalk";
$config['snmptranslate']             = "/usr/local/bin/snmptranslate";
$config['mtr']                       = "/usr/local/sbin/mtr";
$config['nmap']                      = "/usr/local/bin/nmap";
$config['ipmitool']                  = "/usr/local/bin/ipmitool";
$config['git']                       = "/usr/local/bin/git";
$config['dot']                       = "/usr/local/bin/dot";
$config['unflatten']                 = "/usr/local/bin/unflatten";
$config['neato']                     = "/usr/local/bin/neato";
$config['sfdp']                      = "/usr/local/bin/sfdp";

$config['nagplug_dir']               = "/usr/local/libexec/nagios";
$config['rrdcached']                 = "unix:/var/run/rrdcached.sock";

$config['rancid_configs'][]          = "/usr/local/var/rancid/observium/configs/";
$config['rancid_version']            = "3";
$config['rancid_ignorecomments']     = 0;

// End config.php
?>
