<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

destroy_session();
header('Location: /auth/login.php');
exit;
