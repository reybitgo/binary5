<?php
require 'config.php';
if (isset($_SESSION['user_id'])) redirect('dashboard.php');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Rixile</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <h2 class="mb-3">Welcome to Rixile</h2>
  <a href="register.php" class="btn btn-primary">Register Now</a>
  <a href="login.php" class="btn btn-outline-secondary ms-2">Login</a>
</div>
</body>
</html>