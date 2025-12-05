<?php
// templates/sidebar.php
// BASE_URL ya viene desde header.php (config.php)

// Para resaltar el ítem activo en el menú
// En cada página puedes definir, por ejemplo:
//   $currentPage = 'dashboard_global';
if (!isset($currentPage)) {
    $currentPage = '';
}
?>
<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <!-- Brand Logo -->
  <a href="<?= BASE_URL ?>/dashboard/dashboard_global.php" class="brand-link">
    <img src="<?= BASE_URL ?>/assets/img/logo.png"
         alt="Logo"
         style="opacity: .9; width:auto; height:50px; object-fit:cover;">
    <span class="brand-text font-weight-light ml-2"> </span>
  </a>

  <!-- Sidebar -->
  <div class="sidebar">

    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column"
          data-widget="treeview" role="menu" data-accordion="false">

        <!-- DASHBOARD -->
        <li class="nav-item has-treeview <?= in_array($currentPage, ['dashboard_global','dashboard_rutas']) ? 'menu-open' : '' ?>">
          <a href="#" class="nav-link <?= in_array($currentPage, ['dashboard_global','dashboard_rutas']) ? 'active' : '' ?>">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>
              Dashboard
              <i class="right fas fa-angle-left"></i>
            </p>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item">
              <a href="<?= BASE_URL ?>/dashboard/dashboard_global.php"
                 class="nav-link <?= $currentPage === 'dashboard_global' ? 'active' : '' ?>">
                <i class="far fa-circle nav-icon"></i>
                <p>Dashboard Global</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?= BASE_URL ?>/dashboard/dashboard_rutas.php"
                 class="nav-link <?= $currentPage === 'dashboard_rutas' ? 'active' : '' ?>">
                <i class="far fa-circle nav-icon"></i>
                <p>Dashboard de Rutas</p>
              </a>
            </li>
          </ul>
        </li>

        <!-- OPERACIÓN EN RUTA -->
        <li class="nav-item has-treeview <?= in_array($currentPage, ['monitoreo','monitoreo_listado']) ? 'menu-open' : '' ?>">
          <a href="#" class="nav-link <?= in_array($currentPage, ['monitoreo','monitoreo_listado']) ? 'active' : '' ?>">
            <i class="nav-icon fas fa-bus-alt"></i>
            <p>
              Operación en Ruta
              <i class="right fas fa-angle-left"></i>
            </p>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item">
              <a href="<?= BASE_URL ?>/monitoreo/index.php"
                 class="nav-link <?= $currentPage === 'monitoreo' ? 'active' : '' ?>">
                <i class="far fa-circle nav-icon"></i>
                <p>Monitoreo (Agentes)</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?= BASE_URL ?>/monitoreo/lista_reportes.php"
                 class="nav-link <?= $currentPage === 'monitoreo_listado' ? 'active' : '' ?>">
                <i class="far fa-circle nav-icon"></i>
                <p>Reportes del agente</p>
              </a>
            </li>
          </ul>
        </li>

        <!-- ADMINISTRACIÓN -->
        <?php
          // añadimos una clave específica para el resumen:
          // 'admin_resumen_rutas'
          $adminPages = ['admin_rutas', 'admin_ruta', 'admin_resumen_rutas'];
        ?>
        <li class="nav-item has-treeview <?= in_array($currentPage, $adminPages) ? 'menu-open' : '' ?>">
          <a href="#" class="nav-link <?= in_array($currentPage, $adminPages) ? 'active' : '' ?>">
            <i class="nav-icon fas fa-cogs"></i>
            <p>
              Administración
              <i class="right fas fa-angle-left"></i>
            </p>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item">
              <a href="<?= BASE_URL ?>/admin/admin_rutas_listado.php"
                 class="nav-link <?= $currentPage === 'admin_rutas' ? 'active' : '' ?>">
                <i class="far fa-circle nav-icon"></i>
                <p>Rutas</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?= BASE_URL ?>/Reporteria/reporte_rutas_resumen.php"
                 class="nav-link <?= $currentPage === 'admin_resumen_rutas' ? 'active' : '' ?>">
                <i class="far fa-circle nav-icon"></i>
                <p>Resumen de rutas</p>
              </a>
            </li>
          </ul>
        </li>

      </ul>
    </nav>
    <!-- /.sidebar-menu -->
  </div>
  <!-- /.sidebar -->
</aside>
