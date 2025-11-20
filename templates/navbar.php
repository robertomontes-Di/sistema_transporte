<?php
// templates/navbar.php
// BASE_URL ya viene desde header.php (config.php)

?>
<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">

  <!-- Botón para colapsar el sidebar -->
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#" role="button">
        <i class="fas fa-bars"></i>
      </a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?= BASE_URL ?>/dashboard/dashboard_global.php" class="nav-link">
        Sistema de Logística
      </a>
    </li>
  </ul>

  <!-- Menú derecho -->
  <ul class="navbar-nav ml-auto">
    <!-- Aquí puedes poner info del usuario logueado, notificaciones, etc. -->
    <li class="nav-item d-none d-sm-inline-block">
      <span class="nav-link">
        <i class="fas fa-user-circle mr-1"></i> Operador
      </span>
    </li>
  </ul>
</nav>
<!-- /.navbar -->
