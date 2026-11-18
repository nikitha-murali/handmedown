<?php
require_once __DIR__ . '/config.php';
?>
<header class="site-header">
  <div class="shell site-header-inner">
    <a class="brand" href="index.php" aria-label="HandMeDown home"><span class="brand-mark" aria-hidden="true">H</span><span>HandMeDown</span></a>
    <nav class="site-nav" aria-label="Main navigation">
      <a href="browse.php">Browse</a>
      <a href="stories.php">Stories</a>
      <a href="sell.php">Sell</a>
      <a href="hmd-cart.php">Cart</a>
      <?php if (!empty($_SESSION['id'])): ?>
        <a href="profile.php?id=<?= (int) $_SESSION['id'] ?>">My profile</a>
      <?php else: ?>
        <a class="nav-login" href="login.php">Log in</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
