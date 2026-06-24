<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/core/Database.php';
session_start();
require_once ROOT . '/core/AffiliateAuth.php';
AffiliateAuth::logout();
header('Location: /affiliate/login');
