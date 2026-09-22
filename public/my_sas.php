<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// نُقلت إلى الإعدادات — تبويب «تسجيل الدخول عبر SAS»
redirect('settings.php?tab=sas');
