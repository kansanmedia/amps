<?php
// templates/header.php - Global HTML Head & CSS includes
if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars(Helper::getCompanyName()) ?> - Daily Staff Deployment, Attendance, Billing, and Accounting System">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars(Helper::getCompanyName()) ?></title>

    <!-- Bootstrap 5.3 CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <!-- Bootstrap Icons CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css">
    <!-- Custom Enterprise CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= file_exists(ROOT_PATH . '/assets/css/style.css') ? filemtime(ROOT_PATH . '/assets/css/style.css') : time() ?>">
    <!-- Flatpickr Date Picker CSS (Global DD/MM/YYYY Support) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/flatpickr.min.css">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">

    <!-- Bootstrap 5.3 Bundle JS (Loaded early for inline modal controllers) -->
    <script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
</head>
<body>
<div class="sidebar-overlay"></div>
<div class="app-wrapper">
