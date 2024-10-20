# observium-freebsd-install

Manual installation of [Observium](http://www.observium.org) on FreeBSD. Observium is an advanced auto discovering network management system centred around SNMP.

This document is aiming to provide a complete production ready setup.

Updating the FreeBSD port was a thing I discussed with various people but the main obstacle is the fact that Observium has got particular installation paths hardcoded so making it adhere to FreeBSD filesystem hierarchy standards would involve creating and maintaining a ton of patches.

Also customers with a commercial ("professional" or "enterprise" edition) license install and update Observium by checking out directly from the developers' SVN repository.

So a port does not really make sense. I created these - hopefully complete and correct - instructions instead.

## General considerations

- a dedicated machine or jail for Observium
- Apache 2.4 and mod_php for PHP execution
- cron jobs run as root
- all required third party software installed as packages
- keep the default install location of `/opt/observium`

Additionally this guide assumes you are working from a **root** shell. No `sudo` for each command as seems to be preferred in Debian or Ubuntu based howtos, because `sudo` is not part of the FreeBSD base system. Use SSH login to root (following Ubuntu and setting `PermitRootLogin prohibit-password` for that) or use `su -` or `sudo -s` as preferred.

## Installation

Make sure DNS and NTP are configured correctly and working.

### Install required packages

#### Configure `pkg` to use "latest" instead of "quarterly" repository

```sh
mkdir -p /usr/local/etc/pkg/repos
echo 'FreeBSD: { url: "pkg+http://pkg.FreeBSD.org/${ABI}/latest" }' >/usr/local/etc/pkg/repos/FreeBSD.conf
pkg upgrade -y
pkg autoremove -y
```

#### Install packages

```sh
pkg install ImageMagick7-nox11 fping git-tiny graphviz ipmitool mariadb1011-server mtr-nox11 nagios-plugins net-snmp nmap php83 mod_php83 php83-bcmath php83-ctype php83-curl php83-filter php83-gd php83-mbstring php83-mysqli php83-posix php83-session php83-pear-Services_JSON php83-pecl-APCu php83-pecl-mcrypt python python3 py311-pymysql rancid3 rrdtool
```

### Enable and start MariaDB and create the database

#### Enable the database server

```sh
sysrc mysql_enable=YES
service mysql-server start
```

#### Create database and database user

```sql
mysql
root@localhost [(none)]> CREATE DATABASE observium DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
root@localhost [(none)]> CREATE USER 'observium'@'localhost' identified by '<db password>';
root@localhost [(none)]> GRANT ALL ON observium.* TO 'observium'@'localhost';
Ctrl-D
```

### Enable and start rrdcached

```sh
sysrc rrdcached_enable=YES
service rrdcached start
```

### Download and extract Observium

If you want a specific release instead of the latest use a URL like this: <http://www.observium.org/observium-community-23.9.tar.gz>

```sh
mkdir -p /opt/observium
cd /opt
fetch http://www.observium.org/observium-community-latest.tar.gz
tar xvfz observium-community-latest.tar.gz
rm observium-community-latest.tar.gz
chown -R root:wheel observium
mkdir observium/logs observium/rrd
chown www:www observium/rrd
```

### Configure and initialise Observium database

#### Configure Observium

Create `/opt/observium/config.php` with this content:

```php
<?php

$config['db_host']                   = 'localhost';
$config['db_socket']                 = '/var/run/mysql/mysql.sock';
$config['db_name']                   = 'observium';
$config['db_user']                   = 'observium';
$config['db_pass']                   = '<db password>';

// End config.php
?>
```

#### Initialise the database

```sh
cd /opt/observium
./discovery.php -u
```

### Configure Observium 3rd party tools paths

FreeBSD installs optional packages into the `/usr/local` hierarchy. So we need to adjust the default paths of more or less every single external tool.

Add this to `/opt/observium/config.php`:

```php
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
```

### Add Observium cron jobs

#### Edit root's crontab

```sh
crontab -e
```

#### Add cron jobs

```sh
# Run a complete discovery of all devices once every 6 hours
33 */6 * * * /opt/observium/observium-wrapper discovery >/dev/null 2>&1

# Run automated discovery of newly added devices every 5 minutes
*/5 * * * * /opt/observium/observium-wrapper discovery --host new >/dev/null 2>&1

# Run multithreaded poller wrapper every 5 minutes
*/5 * * * * /opt/observium/observium-wrapper poller >/dev/null 2>&1

# Run housekeeping script daily for syslog, eventlog and alert log
13 5 * * * /opt/observium/housekeeping.php -ysel >/dev/null 2>&1

# Run housekeeping script daily for rrds, ports, orphaned entries in the database and performance data
47 4 * * * /opt/observium/housekeeping.php -yrptb >/dev/null 2>&1
```

### Configure, enable and start Apache web server

#### Enable mod_rewrite

```sh
sed -i '' -e 's/^#LoadModule rewrite_module/LoadModule rewrite_module/' /usr/local/etc/apache24/httpd.conf
```

#### Configure vhost

Create `/usr/local/etc/apache24/Includes/observium.conf` with [this content](observium.conf):

```apache
<VirtualHost *:80>
    ServerName <your FQDN>
    DocumentRoot /opt/observium/html
    <FilesMatch \.php$>
      SetHandler application/x-httpd-php
    </FilesMatch>
    <Directory />
            Options FollowSymLinks
            AllowOverride None
    </Directory>
    <Directory /opt/observium/html/>
            DirectoryIndex index.php
            Options Indexes FollowSymLinks MultiViews
            AllowOverride All
            Require all granted
    </Directory>
</VirtualHost>
```

You need some FQDN to access the application, possibly internal. Enabling SSL either via reverse proxy or in Apache is not covered in this guide.

#### Add `/usr/local` paths to Apache runtime environment

On FreeBSD by default server processes like Apache start with a limited search path. This prohibits Observium from detecting Python correctly. So we add the necessary paths now.

```sh
echo 'PATH="/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin"' >/usr/local/etc/apache24/envvars.d/path.env
```

#### Add FQDN to Observium configuration

Add this to `/opt/observium/config.php`:

```php
$config['base_url']                  = 'http://<your FQDN>';
```

#### Enable and start Apache

```sh
sysrc apache24_enable=YES
service apache24 start
```

### Add first administrator user

```sh
cd /opt/observium
./adduser.php admin <password> 10
```

### Login from your Browser

Go to `http://<your FQDN>` as configured in the steps above, login with the admin user just created.

You can now add devices.

### Rancid integration (optional)

[Rancid](https://shrubbery.net/rancid/) is a tool to automatically poll routers, switches and similar devices for configuration changes and put these changes into some version control system like SVN or git.

Configuring Rancid is beyond the scope of this document but since the database paths are FreeBSD specific they are added here for completeness.

Assuming you have a single group in Rancid named `observium`:

```sh
LIST_OF_GROUPS="observium"; export LIST_OF_GROUPS
```

then add this to `/opt/observium/config.php`:

```php
$config['rancid_configs'][]          = "/usr/local/var/rancid/observium/configs/";
$config['rancid_version']            = "3";
$config['rancid_ignorecomments']     = 0;
```

## FreeBSD as a monitored system

FreeBSD comes with its own SNMP server `bsnmpd` but the more common net-snmp package is better supported and offers some extension features we will use.

### Install the Observium distro script

```sh
fetch -o /usr/local/sbin/distro https://raw.githubusercontent.com/observium/distroscript/master/distro
chmod 755 /usr/local/sbin/distro
```

### Install and configure net-snmp

#### Install package

```sh
pkg install net-snmp
```

#### Configure snmpd

Create the file `/usr/local/etc/snmpd.conf` with this content:

```plaintext
agentAddress    udp:161

view            all     included    .1
rocommunity     public  default     -V all

includeAllDisks 10%

sysLocation     <your system location>
sysContact      <your system contact>

# http://oid-info.com/get/1.3.6.1.2.1.1.7
sysServices     72

#  https://docs.observium.org/device_linux
extend          .1.3.6.1.4.1.2021.7890.1 distro     /usr/local/sbin/distro
extend          .1.3.6.1.4.1.2021.7890.2 hardware   /bin/kenv smbios.planar.product
extend          .1.3.6.1.4.1.2021.7890.3 vendor     /bin/kenv smbios.planar.maker
extend          .1.3.6.1.4.1.2021.7890.4 serial     /bin/kenv smbios.planar.serial
```

Depending on the system you might want to replace `planar` with `system` in the lines above. Just try the commands interactively to find out which gives the most useful information.

#### Enable and start net-snmpd

```sh
sysrc snmpd_enable=YES
echo 'snmpd_conffile="/usr/local/etc/snmpd.conf"' >/etc/rc.conf.d/snmpd
service snmpd start
```

## Monitoring FreeBSD on a Raspberry Pi

### SNMP configuration

For a Raspberry Pi running FreeBSD follow the FreeBSD section above but in `/usr/local/etc/snmpd.conf` replace the four `extend` lines with these three instead:

(There is no useful "vendor" information to be gathered from the device itself.)

```plaintext
extend          .1.3.6.1.4.1.2021.7890.1 distro     /usr/local/sbin/distro
extend          .1.3.6.1.4.1.2021.7890.2 hardware   /sbin/sysctl -n hw.fdt.model
extend          .1.3.6.1.4.1.2021.7890.4 serial     /sbin/sysctl -n hw.fdt.serial-number
```

### Linux / Raspberry Pi agent

Observium comes with a Linux agent script that augments the data gathered by SNMP. It's too Linux-centric to be used unmodified but we can replace it with a short shell script of our own and feed core frequency and core temperature into Observium.

#### Deploy the agent script

Deploy this script as `/usr/local/sbin/observium-rpi-agent`:

```sh
#!/bin/sh

# close standard input (for security reasons) and stderr
if [ "$1" = -d ]
then
    set -xv
else
    exec <&- 2>/dev/null
fi

echo '<<<Observium>>>'
echo 'Version: 1.0.0'
echo "AgentOS: $(/usr/bin/uname -o)"

echo '<<<raspberrypi>>>'
echo "clock-core: $(/sbin/sysctl -n dev.cpu.0.freq)"
echo "temp: $(/sbin/sysctl -n dev.cpu.0.temperature)"

exit 0
```

Make it executable: `chmod 755 /usr/local/sbin/observium-rpi-agent`.

#### Configure inetd

```sh
echo "observium-agent 36602/tcp #Observium Unix Agent" >>/etc/services
echo "observium-agent stream tcp nowait nobody /usr/local/sbin/observium-rpi-agent observium-rpi-agent" >>/etc/inetd.conf
```

#### Enable and start inetd

```sh
sysrc inetd_enable=YES
service inetd start
```

#### Restrict access to the agent (optional)

Place this in `/etc/hosts.allow` - at the top right before the `ALL : ALL : allow` line:

```plaintext
observium-rpi-agent : <IP address of your Observium host> : allow
observium-rpi-agent : ALL : deny
```

---
EOF
