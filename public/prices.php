<?php
require_once __DIR__ . '/../includes/bootstrap.php';
redirect('agent_prices.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : ''));
