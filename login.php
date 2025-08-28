<?php
// login.php - User login page
require 'config.php';
if ($_POST) {
    $stmt = $pdo->prepare("SELECT id,password FROM users WHERE username = ?");
    $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        redirect('dashboard.php');
    }
    redirect('login.php', 'Invalid credentials');
}
?>
<!doctype html>
<html>
<head>
  <title>Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:400px">
  <h2 class="mb-4">Login</h2>
  <?php if (isset($_SESSION['flash'])) { echo '<div class="alert alert-warning">'.$_SESSION['flash'].'</div>'; unset($_SESSION['flash']); } ?>
  <form method="post">
    <div class="mb-3">
      <label>Username</label>
      <input type="text" class="form-control" name="username" required>
    </div>
    <div class="mb-3">
      <label>Password</label>
      <input type="password" class="form-control" name="password" required>
    </div>
    <button class="btn btn-primary">Login</button>
    <a href="index.php" class="btn btn-outline-secondary">Back</a>
  </form>
</div>
</body>
</html>