<?php
// modules/auth/logout.php - Terminate User Session
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::logout();
Helper::setFlash('info', 'You have been signed out safely.');
Helper::redirect('modules/auth/login.php');
