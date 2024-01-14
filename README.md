# observium-freebsd-install

Manual installation of Observium on FreeBSD. This is aiming to provide a complete production ready setup. It might get used to create an up to date port later.

## Prerequisites

I assume a dedicated machine or jail for Observium. I'll follow the [Debian 12 instructions'](https://docs.observium.org/install_debian/#manual-installation) lead and use the easy way for this first PoC:

- Apache 2.4 and mod_php for PHP execution
- cron jobs run as root
- all required third party software installed as packages
- keep the default install location of `/opt/observium`

Additionally I assume you are working from a **root** shell. No `sudo` for each command as seems to be preferred in Debian or Ubuntu based howtos. Use SSH login to root (I like to follow Ubuntu and set `PermitRootLogin prohibit-password` for that) or use `su -` or `sudo -s` as you prefer.

## Installation

Make sure DNS and NTP are configured correctly and working.

### Install required packages

Configure `pkg` to use "latest" instead of "quarterly" repository:

```sh
mkdir -p /usr/local/etc/pkg/repos
echo 'FreeBSD: { url: "pkg+http://pkg.FreeBSD.org/${ABI}/latest" }' >/usr/local/etc/pkg/repos/FreeBSD.conf
```

Install packages:

```sh
pkg install ImageMagick7 fping graphviz ipmitool mariadb1011-server mtr-nox11 nagios-plugins net-snmp nmap php82 mod_php82 php82-bcmath php82-ctype php82-curl php82-filter php82-gd php82-mbstring php82-mysqli php82-posix php82-session php82-pear-Services_JSON php82-pecl-APCu php82-pecl-mcrypt python py39-pymysql rancid3 rrdtool
```

### Enable and start MariaDB and create the database

#### Enable the database server:

```sh
sysrc mysql_enable=YES
service mysql-server start
```

#### Create database and database user:

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

*[Complete sample config](config.php)*

#### Configure Observium

Create `/opt/observium/config.php` with this content:

```php
<?php

$config['db_host']                   = '127.0.0.1';
$config['db_name']                   = 'observium';
$config['db_user']                   = 'observium';
$config['db_pass']                   = '<db password>';

// End config.php
?>
```

Using `127.0.0.1` instead of `localhost` works around FreeBSD and Observium assuming different locations for the local Unix domain socket. The perfomance impact is negligible.

#### Initialise the database:

```sh
cd /opt/observium
./discovery.php -u
```

### Configure Observium 3rd party tools paths

*[Complete sample config](config.php)*

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
$config['whois']                     = "/usr/bin/whois";
$config['mtr']                       = "/usr/local/sbin/mtr";
$config['nmap']                      = "/usr/local/bin/nmap";
$config['ipmitool']                  = "/usr/local/bin/ipmitool";
$config['git']                       = "/usr/local/bin/git";
$config['nagplug_dir']               = "/usr/local/libexec/nagios";
$config['rrdcached']                 = "unix:/var/run/rrdcached.sock";
```

### Add Observium cron jobs

#### Edit root's crontab:

```sh
crontab -e
```

#### Add this:

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

You need some FQDN to access the application, possibly internal. I use Caddy as an SSL reverse proxy for all applications so Apache serves plain HTTP only. For this PoC we'll leave it at plain HTTP first, then add Caddy later.

#### Add `/usr/local` paths to Apache runtime environment

On FreeBSD by default server processes like Apache start with a limited search path. This prohibits Observium from detecting Python correctly. So we add the necessary paths now.

```sh
echo 'PATH="/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin"' >/usr/local/etc/apache24/envvars.d/path.env
```

#### Add FQDN to Observium configuration

*[Complete sample config](config.php)*

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

### Caddy SSL reverse proxy (optional)

Caddy provides fully automated handling of SSL/HTTPS and Letsencrypt certificates. So if this is an installation that is publicly reachable, enabling SSL is more or less mandatory. Caddy is so much superior as a reverse proxy compared to Apache or even NginX that I prefer to run a separate process for SSL.

#### Install and enable Caddy

```sh
pkg install caddy
sysrc caddy_enable=YES
```

#### Configure Caddy

Place this into `/usr/local/etc/caddy/Caddyfile`:

```caddy
<your FQDN> {
  reverse_proxy * {
    to http://127.0.0.1
  }
}
```

#### Tell Observium to use HTTPS

In `/opt/observium/config.php` change this:

```php
$config['base_url']                  = 'http://<your FQDN>';
```

to this:

```php
$config['base_url']                  = 'https://<your FQDN>';
```

#### Start Caddy

```sh
service caddy start
```

### Rancid integration (optional)

Configuring Rancid is beyond the scope of this document but since the database paths are FreeBSD specific I'll add the necessary configuration of Observium here.

Assuming you have a single group in Rancid named `observium`:

```sh
LIST_OF_GROUPS="observium"; export LIST_OF_GROUPS
```

then add this to `/opt/observium/config.php`:

*[Complete sample config](config.php)*

```php
$config['rancid_configs'][]          = "/usr/local/var/rancid/observium/configs/";
$config['rancid_version']            = "3";
$config['rancid_ignorecomments']     = 0;
```

---
EOF
