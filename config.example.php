<?php
// Copy to config.local.php and fill in values. Keep config.local.php out of web root and Git.
define('TDC_DB_HOST', 'localhost');
define('TDC_DB_NAME', 'your_database');
define('TDC_DB_USER', 'your_database_user');
define('TDC_DB_PASS', 'use-a-long-unique-database-password');

// Set true on production when TLS is enabled (recommended).
define('TDC_FORCE_HTTPS', true);

// Generate a unique 32+ character random key; keep it backed up and private.
define('TDC_APP_KEY', '');

// Xdigitex Pay credentials. Keep these values on the server only.
define('TDC_PAY_API_KEY', '');
define('TDC_PAY_WEBHOOK_SECRET', ''); // Generate a separate random 32+ character secret.
define('TDC_APP_URL', 'https://your-domain.example');

// Add SMTP provider values when ready. The CLI notification worker keeps queued
// email safely pending until all required settings are present.
define('TDC_SMTP_HOST', '');
define('TDC_SMTP_PORT', 587);
define('TDC_SMTP_ENCRYPTION', 'tls'); // tls (STARTTLS) or ssl
define('TDC_SMTP_USER', '');
define('TDC_SMTP_PASS', '');
define('TDC_SMTP_FROM', '');
define('TDC_SMTP_FROM_NAME', 'TDC Tech');

// Google OAuth ("Sign in with Google"). Fill these to enable the Google button.
define('TDC_GOOGLE_CLIENT_ID', '');
define('TDC_GOOGLE_CLIENT_SECRET', '');
