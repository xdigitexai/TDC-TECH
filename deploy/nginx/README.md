# Nginx vhosts

Reference copies of the live vhosts for `tdctech.org` and its
`tdc-tech.novaspack.com` twin. Certificate, key and DH parameter paths are
intentionally omitted because they are host-managed by Certbot; everything
else mirrors the live configuration.

## Routes added for the service upgrades

Both vhosts must contain these rewrites. The clean URL is what the dashboard
uses; the `.php` targets are the actual service pages.

| Request | Rewrite target |
| --- | --- |
| `/dashboard/services/account-management` | `/services/account-mgmt.php` |
| `/dashboard/services/custom-bots` | `/services/bots.php` |
| `/services/account-management` | `/services/account-mgmt.php` |
| `/services/custom-bots` | `/services/bots.php` |

Without the two `/dashboard/services/...` entries the Account Management and
Custom Bots URLs return 404.

## Upload body size

Both vhosts set:

```nginx
client_max_body_size 220m;
```

This leaves headroom for the 200 MB service asset limit plus multipart
overhead.

## PHP upload limits

`public/.user.ini` in the repository root of the site sets:

```ini
upload_max_filesize=210M
post_max_size=220M
memory_limit=256M
```

After changing `.user.ini`, reload PHP-FPM (`systemctl reload php8.3-fpm`) so
the per-directory ini cache is refreshed.

## Deploying

1. Copy the relevant `*.conf` into `/etc/nginx/sites-enabled/` (keep the
   existing Certbot-managed certificate lines).
2. Ensure `public/.user.ini` matches the values above.
3. Run `nginx -t`, then reload nginx and PHP-FPM.
