# Denon CEOL Remote · 8.2.0

Single-file PHP 7.2+ remote for the Denon CEOL/RCD-N9, with mini/full layouts, ten themes, V8 Theme Studio, and confirmed admin updates. No database, Composer, or JavaScript libraries are needed to deploy it.

1. Put `index.php` on a PHP webserver. PHP needs write access to its folder for preferences and admin data.
2. Enable **Network Control / IP Control** on the Denon and find its IPv4 address.
3. Open the app and choose a connection mode in **Connection settings** (the IP button in the header).
4. Save the IP and mode. Both modes use the same controls, validation, serial command queue, themes, and editor.

| Mode | Computer that reaches the Denon | Requirements |
| --- | --- | --- |
| Server relay (existing/default) | PHP host → TCP port 23, or selected HTTP command fallback | PHP must be on the Denon LAN or have a VPN route to it. Status polling stays available. |
| Browser direct | Browser → Denon HTTP goform API | Browser must reach the Denon LAN. PHP can be hosted remotely and needs no LAN/VPN route. Configure the Denon's HTTP/HTTPS protocol and port explicitly. |

Browser-direct failures never switch transports, protocols, ports, or response modes, and never retry a command through PHP. The existing relay HTTP command fallback remains an explicit relay option with its existing port probes. Switching connection settings cancels queued commands and discards replies from the old connection. PHP also rejects relay-control requests while browser-direct mode is selected, so a stale tab cannot accidentally contact the Denon through the server.

## Browser-direct replies and failures

**Dispatch only** uses `no-cors`, as the original `localwifi.php` did. An opaque response cannot confirm delivery, HTTP errors, or device state. The app shows **Direct · Unconfirmed**, and power/source/mute/volume changes are labeled as estimates. Live status polling is disabled; **Refresh** and **Test** send a single read-only `PW?` transport probe, not a full status refresh.

**Check HTTP replies** uses `cors`. Choose it only if the Denon allows this app's origin. Readable HTTP failures are reported, and a successful response shows **Direct · HTTP replied**. This confirms an HTTP response, not execution of the command or full live status; displayed values remain estimates.

Direct requests time out after six seconds. Failed direct requests stop pending commands and show a persistent diagnostic above the remote:

- An HTTPS app requesting an HTTP Denon may be blocked as mixed content. Supporting browsers can permit this through **Local Network Access**. Other browsers may require serving the app over HTTP on the LAN, or a Denon HTTPS endpoint with a browser-trusted certificate.
- Denied local/private-network permission is reported when the browser exposes its permission state. Allow it in site settings, and check any site or embedded-frame policy.
- CORS readback needs a compatible device response. Dispatch-only mode avoids ordinary response-read CORS requirements but does not bypass mixed-content or local/private-network restrictions.
- Check the Denon's power, Network Control, IP, port, and web interface from the same browser. HTTPS certificate errors also block requests.

Browsers often expose CORS, mixed-content, TLS, and unreachable-device failures as the same `TypeError`. The diagnostic states that uncertainty and points to the browser console/Network panel. A failed or timed-out command may already have reached the device; inspect the Denon before repeating it.

`localwifi.php` is now a redirect to the shared interface. It opens Connection settings with browser-direct suggested and can prefill a legacy browser-saved URL when no IP is configured. The user must save before that suggestion changes the selected transport.

## Theme Studio and updates

Open **Theme editor** and create an admin password on first use. Preferences, connection settings, and custom layouts share `denon-ceol-preferences.json`; admin credentials stay in the guarded `.denon-ceol-admin.php`. Existing 8.1 preferences keep their themes and relay behavior. Deploying only `index.php` is sufficient; the legacy redirect and tests are optional.

The updater checks this repository's `main/index.php`, requires confirmation and admin authentication, preserves settings, and creates a recovery backup. Bump `APP_VERSION` for each release.

## Regression checks

Run from the repository root with PHP 7.2+ and Node 18+ (Node is only a development test runner):

```sh
php -n -l index.php
php -n -l localwifi.php
php -n tests/connection_test.php
node --test tests/*.test.cjs
```

Set `PHP_BINARY` if the PHP executable is not named `php`. Tests use temporary preference files and mocked browser requests; they do not contact a physical Denon. Check real-device behavior and browser permission prompts separately on the user's LAN.
