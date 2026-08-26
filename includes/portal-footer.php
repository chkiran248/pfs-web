    </main><!-- /.portal-content -->

  </div><!-- /.portal-main -->

</div><!-- /.portal-wrapper -->

<!-- WhatsApp Float Button -->
<a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=Hi%2C+I+need+help+with+my+finances+on+primefin.in"
   class="whatsapp-float" target="_blank" rel="noopener noreferrer">
  <span>💬</span> Chat with Advisor
</a>

<!-- ── BOTTOM NAVIGATION (mobile only) ── -->
<?php
$_bnav = match(true) {
    $current_page === 'dashboard'  => 'dashboard',
    $current_page === 'portfolio'  => 'portfolio',
    $current_page === 'primo'      => 'primo',
    $current_page === 'goals'      => 'goals',
    $current_page === 'profile'    => 'profile',
    default                        => '',
};
?>
<nav class="bottom-nav" aria-label="Mobile navigation">
  <a href="<?= SITE_URL ?>/portal/dashboard.php"
     class="bottom-nav-item<?= $_bnav==='dashboard'?' active':'' ?>">
    <i class="bi bi-<?= $_bnav==='dashboard'?'house-fill':'house' ?>"></i>
    <span>Home</span>
  </a>
  <a href="<?= SITE_URL ?>/portal/portfolio.php"
     class="bottom-nav-item<?= $_bnav==='portfolio'?' active':'' ?>">
    <i class="bi bi-<?= $_bnav==='portfolio'?'pie-chart-fill':'pie-chart' ?>"></i>
    <span>Portfolio</span>
  </a>
  <a href="<?= SITE_URL ?>/portal/primo.php"
     class="bottom-nav-item bottom-nav-primo<?= $_bnav==='primo'?' active':'' ?>">
    <i class="bi bi-stars"></i>
    <span>PrimoAI</span>
  </a>
  <a href="<?= SITE_URL ?>/portal/goals.php"
     class="bottom-nav-item<?= $_bnav==='goals'?' active':'' ?>">
    <i class="bi bi-<?= $_bnav==='goals'?'bullseye':'crosshair' ?>"></i>
    <span>Goals</span>
  </a>
  <a href="<?= SITE_URL ?>/portal/profile.php"
     class="bottom-nav-item<?= $_bnav==='profile'?' active':'' ?>">
    <i class="bi bi-<?= $_bnav==='profile'?'person-fill':'person' ?>"></i>
    <span>Profile</span>
  </a>
</nav>

<script src="<?= SITE_URL ?>/assets/js/portal.js?v=20260826"></script>
</body>
</html>
