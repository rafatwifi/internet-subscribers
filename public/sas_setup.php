<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_perm('settings');
redirect('settings.php?tab=sas');
