<?php
// Get credentials from Google Cloud Console: https://console.cloud.google.com/
define('GOOGLE_CLIENT_ID',     'YOUR_CLIENT_ID_HERE.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_CLIENT_SECRET_HERE');
define('GOOGLE_REDIRECT_URI',  APP_URL . '/auth/google-callback.php');

define('GOOGLE_AUTH_URL',    'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL',   'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL','https://www.googleapis.com/oauth2/v3/userinfo');
define('GOOGLE_SCOPES',      'openid email profile');
