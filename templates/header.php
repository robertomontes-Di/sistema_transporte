<?php
// templates/header.php

// Cargar configuración global
require_once __DIR__ . '/../includes/config.php';

// Título por defecto si la página no define $pageTitle
if (!isset($pageTitle)) {
    $pageTitle = 'Sistema de Transporte';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 4.6.2 -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/dist/bootstrap-4.6.2/css/bootstrap.min.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/dist/fontawesome/css/fontawesome.css">
    <!-- Si tienes all.min.css también -->
    <!-- <link rel="stylesheet" href="<?= BASE_URL ?>/dist/fontawesome/css/all.min.css"> -->

    <!-- AdminLTE 3.2.0 -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/dist/AdminLTE-3.2.0/dist/css/adminlte.min.css">

    <!-- Estilos personalizados -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css">

    <!-- Apache ECharts (CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/echarts/dist/echarts.min.js"></script>
</head>

<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

<?php
// Barra superior y menú lateral
include __DIR__ . '/navbar.php';
include __DIR__ . '/sidebar.php';
?>

<!-- Contenedor principal del contenido -->
<div class="content-wrapper">
