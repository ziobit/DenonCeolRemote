# Denon CEOL Remote · Micro Remote V7

Single-file PHP 7.2+ remote for the Denon CEOL/RCD-N9. It uses no database, Composer, or JavaScript libraries.

1. Put `index.php` on a PHP webserver that can reach the Denon on TCP port 23.
2. Enable **Network Control / IP Control** on the Denon.
3. Open the app. It connects to `192.168.23.180` by default; choose **Change IP** to save another address.

The selected Denon IP is stored in `denon-ip.txt` next to `index.php`, shared across browsers. The web server needs write permission on that folder. If the file is unreadable or invalid, the default IP is used and the problem is written to the PHP error log.

Only one page controls the Denon at a time. Pages identify themselves with a random ID; hiding or closing the active page releases the session, and abandoned sessions expire after a timeout. The remote uses a light theme and phone-sized section tabs.

`localwifi.php` is retained as a legacy redirect to the shared interface.

## Regression checks

Run from the repository root with PHP 7.2+ and Node 18+:

```sh
php -n -l index.php
php -n -l localwifi.php
php -n tests/connection_test.php
node --test tests/*.test.cjs
```

Tests use temporary files and mocked HTTP requests; they do not contact a physical Denon.
