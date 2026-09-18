---
title: "How to Install a Bulk DNC Tool in VICIdial (System, Campaign and Inbound Blocking)"
slug: vicidial-bulk-dnc-tool-installation
meta_description: "Install a secure VICIdial bulk DNC tool that adds multiple numbers to System DNC, Campaign DNC and inbound filter groups, including remote DB setup."
excerpt: "This step-by-step guide shows how to install and secure the HBTutorial VICIdial Bulk DNC Tool, connect it to a local or remote MariaDB server, and verify that numbers are blocked correctly."
tags: VICIdial, DNC, Do Not Call, Bulk DNC, Campaign DNC, Inbound Call Blocking, MariaDB, PHP, Call Center, HBTutorial
---

# How to Install a Bulk DNC Tool in VICIdial

Adding several Do Not Call numbers through VICIdial one at a time can be slow. The HBTutorial VICIdial Bulk DNC Tool provides one page where an administrator can paste multiple phone numbers and add them to one or more DNC destinations.

The tool supports:

- VICIdial System/Global DNC (`vicidial_dnc`)
- One Campaign DNC or all active Campaign DNCs (`vicidial_campaign_dnc`)
- One inbound filter phone group or all filter groups (`vicidial_filter_phone_numbers`)
- One number per line, comma-separated entries or semicolon-separated entries
- Automatic removal of spaces, parentheses and dashes
- Automatic removal of a leading `1` from an 11-digit US number
- Duplicate detection
- Invalid-number reporting
- Up to 5,000 unique numbers per submission
- Local or remote MariaDB databases

> Important: This version intentionally does not insert records into `vicidial_dnc_log`. It writes directly to the active DNC tables. Therefore, a number can be actively blocked even when the standard VICIdial “Search DNC List Logs” page shows no history.

## Requirements

Before starting, make sure you have:

- A working VICIdial installation
- PHP with the `mysqli` extension
- Access to the VICIdial web server
- MariaDB credentials with the required permissions
- The `VICIdial-Bulk-DNC-Tool.php` file
- A backup of any existing custom DNC page

The examples below use this web directory:

```text
/var/www/html/custom/
```

If your VICIdial web root is `/srv/www/htdocs`, use this location instead:

```text
/srv/www/htdocs/custom/
```

## Step 1: Download the PHP File

Download the latest `VICIdial-Bulk-DNC-Tool.php` file to your computer and upload it to the VICIdial web server.

You can use SCP from Linux, macOS or Windows PowerShell:

```bash
scp VICIdial-Bulk-DNC-Tool.php root@YOUR_WEB_SERVER_IP:/root/
```

Replace `YOUR_WEB_SERVER_IP` with the IP address of your VICIdial web server.

## Step 2: Back Up an Existing Copy

If the tool is already installed, create a timestamped backup before replacing it:

```bash
cp /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php \
   /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php.backup-$(date +%Y%m%d-%H%M%S)
```

If the file does not exist yet, you can skip this step.

## Step 3: Configure the Database Connection

Open the PHP file:

```bash
vi /root/VICIdial-Bulk-DNC-Tool.php
```

Locate the database configuration:

```php
$db_host = "127.0.0.1";
$db_user = "cron";
$db_pass = "YOUR_DATABASE_PASSWORD";
$db_name = "asterisk";
```

### Local database

If MariaDB is installed on the same server as the web page, use:

```php
$db_host = "127.0.0.1";
```

### Remote database

If the VICIdial database is hosted on another server, enter its private IP address:

```php
$db_host = "10.10.10.20";
```

Do not use `127.0.0.1` when the database is on another server. Use a private network whenever possible, and never expose MariaDB port `3306` to the entire internet.

## Step 4: Create a Restricted Database User

Using a dedicated database account is safer than placing the MariaDB root password in a PHP file.

On the database server, replace `WEB_SERVER_IP` and the example password:

```sql
CREATE USER 'hbt_dnc'@'WEB_SERVER_IP'
IDENTIFIED BY 'CHANGE_THIS_TO_A_STRONG_PASSWORD';

GRANT SELECT ON asterisk.vicidial_campaigns
TO 'hbt_dnc'@'WEB_SERVER_IP';

GRANT SELECT ON asterisk.vicidial_filter_phone_groups
TO 'hbt_dnc'@'WEB_SERVER_IP';

GRANT SELECT, INSERT ON asterisk.vicidial_dnc
TO 'hbt_dnc'@'WEB_SERVER_IP';

GRANT SELECT, INSERT ON asterisk.vicidial_campaign_dnc
TO 'hbt_dnc'@'WEB_SERVER_IP';

GRANT SELECT, INSERT ON asterisk.vicidial_filter_phone_numbers
TO 'hbt_dnc'@'WEB_SERVER_IP';

FLUSH PRIVILEGES;
```

Update the PHP configuration:

```php
$db_host = "REMOTE_DATABASE_IP";
$db_user = "hbt_dnc";
$db_pass = "CHANGE_THIS_TO_A_STRONG_PASSWORD";
$db_name = "asterisk";
```

Test the connection from the web server:

```bash
mysql -h REMOTE_DATABASE_IP -u hbt_dnc -p asterisk
```

After connecting, test read access:

```sql
SELECT campaign_id, campaign_name
FROM vicidial_campaigns
LIMIT 5;
```

## Step 5: Restrict Remote MariaDB Access

Allow TCP port `3306` only from the web server’s IP address.

Example using firewalld on the database server:

```bash
firewall-cmd --permanent \
  --add-rich-rule='rule family="ipv4" source address="WEB_SERVER_IP/32" port protocol="tcp" port="3306" accept'

firewall-cmd --reload
```

Do not add a public rule that allows port `3306` from every IP address.

## Step 6: Install the Tool

Copy the configured file into the custom web directory:

```bash
mkdir -p /var/www/html/custom
cp /root/VICIdial-Bulk-DNC-Tool.php /var/www/html/custom/
```

For AlmaLinux, Rocky Linux or CentOS with Apache:

```bash
chown root:apache /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php
chmod 640 /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php
```

For ViciBox/openSUSE, the Apache group may be `www` or the service user may be `wwwrun`. Check it first:

```bash
ps -eo user,group,comm | grep -E 'apache2|httpd' | head
```

Then apply the correct group ownership.

## Step 7: Validate the PHP File

Check for PHP syntax errors:

```bash
php -l /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php
```

Expected result:

```text
No syntax errors detected in /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php
```

Confirm that `mysqli` is enabled:

```bash
php -m | grep -i mysqli
```

If nothing is returned, install or enable the PHP MySQL extension for your operating system, then restart PHP-FPM and Apache.

## Step 8: Protect the DNC Page

This tool does not include its own login screen. Anyone who can open the URL could add numbers to DNC. Protect it with a VPN, an IP allowlist or Apache Basic Authentication.

Create a password file outside the public web directory:

```bash
htpasswd -c /etc/httpd/.htpasswd-dnc hbtadmin
```

Add protection to the appropriate Apache virtual host:

```apache
<Location "/custom/VICIdial-Bulk-DNC-Tool.php">
    AuthType Basic
    AuthName "HBTutorial DNC Administration"
    AuthUserFile /etc/httpd/.htpasswd-dnc
    Require valid-user
</Location>
```

Validate and reload Apache:

```bash
apachectl configtest
systemctl reload httpd
```

On ViciBox/openSUSE, the service is normally `apache2` and the password-file directory may be `/etc/apache2/`.

## Step 9: Open the Tool

Open the following address in a browser:

```text
https://YOUR_VICIDIAL_DOMAIN/custom/VICIdial-Bulk-DNC-Tool.php
```

The page should display:

- A large phone-number input box
- System/Global DNC option
- Campaign DNC option
- Campaign selection
- Inbound filter option
- Inbound filter group selection
- Reason/description field

If campaigns and filter groups appear in the dropdowns, the database connection and read permissions are working.

## Step 10: Submit a Small Test

Start with one test number rather than a large production batch:

```text
3322200519
```

Select only the required destinations. For example:

1. Select **System/Global DNC** to block the number across the system.
2. Select **Campaign DNC** and choose one campaign to block it only for that campaign.
3. Select **Inbound Filter Phone Group** only when you also need inbound blocking.
4. Click **Add Numbers to Selected DNC Options**.

The result panel reports how many records were added, already existed, failed or were invalid.

## Step 11: Add Multiple Numbers

The recommended format is one phone number per line:

```text
3322200519
4453100585
12125550123
(305) 555-0123
```

Commas and semicolons are also accepted:

```text
3322200519, 4453100585; 3055550123
```

The tool normalizes the input before inserting it. An 11-digit US number beginning with `1` is stored as a 10-digit number.

When using a remote database, start with batches of approximately 100–500 numbers. Selecting **ALL ACTIVE CAMPAIGNS** or **ALL FILTER PHONE GROUPS** creates more remote database operations and may take longer.

## Step 12: Verify the Active DNC Records

Verify a System DNC entry:

```sql
SELECT *
FROM vicidial_dnc
WHERE phone_number = '3322200519';
```

Verify Campaign DNC entries:

```sql
SELECT phone_number, campaign_id
FROM vicidial_campaign_dnc
WHERE phone_number = '3322200519';
```

Verify an inbound filter entry:

```sql
SELECT filter_phone_group_id, phone_number, phone_description
FROM vicidial_filter_phone_numbers
WHERE phone_number = '3322200519';
```

These active tables determine whether the number was added by this tool.

## Why the VICIdial DNC Log Search Can Be Empty

The standard VICIdial administration page may display this message:

```text
SEARCHING FOR PHONE NUMBER IN DNC LIST LOGS: 3322200519
```

It can then show no history even though the number is blocked. This is expected because this custom tool writes directly to the active DNC tables and intentionally does not modify `vicidial_dnc_log`.

Always verify the appropriate active table:

- `vicidial_dnc` for System/Global DNC
- `vicidial_campaign_dnc` for Campaign DNC
- `vicidial_filter_phone_numbers` for inbound filtering

No extra log-table insert is required for the number to remain active in the selected DNC destination.

## Troubleshooting HTTP Error 500

### Check the PHP syntax

```bash
php -l /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php
```

### Check Apache and PHP-FPM logs

AlmaLinux, Rocky Linux or CentOS:

```bash
tail -n 100 /var/log/httpd/error_log
journalctl -u php-fpm -n 100 --no-pager
```

ViciBox/openSUSE:

```bash
tail -n 100 /var/log/apache2/error_log
journalctl -u php-fpm -n 100 --no-pager
```

### Page opens but fails after submission

If the dropdowns load but the request fails only after clicking the submit button, the initial database connection is already working. Common causes include:

- A remote database timeout
- PHP maximum execution time
- Apache proxy/FastCGI timeout
- Missing `INSERT` permission
- A large batch combined with all campaigns or all filter groups

Test again using one number and only one destination. Also verify the account permissions:

```sql
SHOW GRANTS;
```

### Test remote connectivity

From the web server:

```bash
nc -vz REMOTE_DATABASE_IP 3306
mysql -h REMOTE_DATABASE_IP -u hbt_dnc -p asterisk
```

If the connection is blocked, check MariaDB’s bind address, the database firewall and any provider-level firewall.

### Verify the database host

If the database is remote, this is incorrect:

```php
$db_host = "127.0.0.1";
```

Use the database server’s private IP instead:

```php
$db_host = "10.10.10.20";
```

## Recommended Production Practices

- Protect the page with authentication and an IP allowlist.
- Use HTTPS.
- Use a dedicated MariaDB account with minimum permissions.
- Keep the database password out of screenshots and public tutorials.
- Never expose MariaDB port `3306` globally.
- Begin remote-database imports with small batches.
- Back up the PHP file before every update.
- Verify active DNC tables after the first test.
- Do not use real customer phone numbers in a public video demonstration.

## Uninstalling the Tool

Removing the PHP page does not delete DNC records already stored in VICIdial.

To disable the page without immediately deleting it:

```bash
mv /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php \
   /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php.disabled
```

To restore a previous copy:

```bash
cp /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php.backup-YYYYMMDD-HHMMSS \
   /var/www/html/custom/VICIdial-Bulk-DNC-Tool.php
```

## Conclusion

The HBTutorial VICIdial Bulk DNC Tool makes it easier to add multiple phone numbers to System DNC, Campaign DNC and inbound filter phone groups. With restricted database permissions, HTTPS and page-level authentication, it can provide a practical administration workflow without modifying the VICIdial core files.

Always test with a small batch first, especially when the PHP page connects to a remote MariaDB server.

---

## Suggested YouTube Title

How to Install a Bulk DNC Tool in VICIdial | System, Campaign and Inbound Blocking

## Suggested YouTube Description

Learn how to install the HBTutorial VICIdial Bulk DNC Tool and add multiple phone numbers to the System DNC, Campaign DNC and inbound filter phone groups. This tutorial covers local and remote MariaDB configuration, restricted database permissions, Apache security, testing and HTTP Error 500 troubleshooting.

## Suggested Thumbnail Text

VICIDIAL BULK DNC TOOL

ADD MULTIPLE NUMBERS

## Suggested Social Caption

New HBTutorial guide: Install a VICIdial Bulk DNC Tool that supports System DNC, Campaign DNC and inbound filter groups. The tutorial includes remote MariaDB setup, security and troubleshooting steps.

