<?php
require 'config.php';

if ($_POST) {
    $username     = trim($_POST['username']);
    $password     = $_POST['password'];
    $sponsor      = trim($_POST['sponsor_name']);
    $uplineUser   = trim($_POST['upline_username']);
    $position     = $_POST['position']; // left / right

    // check upline
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$uplineUser]);
    $upline = $stmt->fetch();
    if (!$upline) redirect('register.php', 'Upline username not found');

    $uplineId = $upline['id'];

    // check chosen side free
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE upline_id = ? AND position = ?");
    $stmt->execute([$uplineId, $position]);
    if ($stmt->fetch()) redirect('register.php', 'Chosen side is already occupied');

    // create user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username,password,sponsor_name,upline_id,position)
                   VALUES (?,?,?,?,?)")
        ->execute([$username,$hash,$sponsor,$uplineId,$position]);
    $uid = $pdo->lastInsertId();

    // create wallet
    $pdo->prepare("INSERT INTO wallets (user_id,balance) VALUES (?,0.00)")->execute([$uid]);

    redirect('login.php', 'Registration successful. Login now.');
}
?>
<!doctype html>
<html>
<head>
  <title>Register</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:500px">
  <h2 class="mb-4">Free Registration</h2>
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
    <div class="mb-3">
      <label>Sponsor Name</label>
      <input type="text" class="form-control" name="sponsor_name" required>
    </div>
    <div class="mb-3">
      <label>Upline Username</label>
      <input type="text" class="form-control" name="upline_username" required>
    </div>
    <div class="mb-3">
      <label>Position</label>
      <select class="form-select" name="position" required>
        <option value="left">Left</option>
        <option value="right">Right</option>
      </select>
    </div>
    <button class="btn btn-success">Register</button>
    <a href="index.php" class="btn btn-outline-secondary">Back</a>
  </form>
</div>
</body>
</html>