<?php
// referral_calc.php - Fixed referral commission calculation
require_once 'config.php';

/**
 * Pay direct-referral commission to the sponsor of the purchasing user.
 *
 * @param int   $buyerId   User who just bought the package
 * @param float $pkgPrice  Package price in dollars
 * @param PDO   $pdo       Active DB connection
 */
function calc_referral(int $buyerId, float $pkgPrice, PDO $pdo): void
{
    /*-------------------------------------------------
     * 1. Fetch the sponsor (direct referrer) of buyer
     *------------------------------------------------*/
    $stmt = $pdo->prepare(
        'SELECT sponsor_id FROM users WHERE id = ?'
    );
    $stmt->execute([$buyerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['sponsor_id'])) {
        // No sponsor → nothing to pay
        return;
    }

    /*-------------------------------------------------
     * 2. Resolve sponsor's user-id
     *------------------------------------------------*/
    $sponsorId = (int)$row['sponsor_id'];

    /*-------------------------------------------------
     * 3. Calculate & credit commission
     *------------------------------------------------*/
    $pkgStmt = $pdo->prepare("SELECT referral_rate FROM packages WHERE id = (
        SELECT package_id FROM wallet_tx
        WHERE user_id = ? AND type='package' ORDER BY id DESC LIMIT 1
    )");
    $pkgStmt->execute([$buyerId]);
    $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pkg) {
        // Fallback to default if no package found
        $referralRate = 0.10; // Default 10%
    } else {
        $referralRate = (float)$pkg['referral_rate'];
    }

    $commission = $pkgPrice * $referralRate;

    if ($commission <= 0.00) {
        return;
    }

    // Credit wallet
    $pdo->prepare(
        'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
    )->execute([$commission, $sponsorId]);

    // Record transaction
    $pdo->prepare(
        'INSERT INTO wallet_tx (user_id, type, amount)
         VALUES (?, "referral_bonus", ?)'
    )->execute([$sponsorId, $commission]);
}
?>