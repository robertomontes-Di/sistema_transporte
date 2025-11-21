<?php
// templates/header.php

// Cargar configuración global (debe definir BASE_URL)
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
      <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favicon.png">

    <!-- ===========================
         CSS PRINCIPALES (CDN)
    ============================ -->

    <!-- Bootstrap 4.6.2 -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

    <!-- Font Awesome 5.15.4 -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <!-- OverlayScrollbars (recomendado por AdminLTE) -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/overlayscrollbars/css/OverlayScrollbars.min.css">

    <!-- AdminLTE 3.2.0 -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">

    <!-- Estilos personalizados -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css">

    <!-- ===========================
         JS GLOBAL EN <head>
    ============================ -->

    <!-- jQuery (necesario ANTES de DataTables y scripts de página) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

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
