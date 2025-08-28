<?php
// reset_pairs.php - Reset daily pairs for all users
require 'config.php';
$pdo->exec("UPDATE users SET pairs_today = 0");
echo "Pairs reset done.\n";