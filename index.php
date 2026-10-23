<?php
// index.php - Main Application Dispatcher
require_once __DIR__ . '/config/config.php';

if (Auth::check()) {
    Helper::redirect('modules/dashboard/index.php');
} else {
    Helper::redirect('modules/auth/login.php');
}
