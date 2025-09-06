<?php
// referral_calc.php - Fixed referral commission calculation with package limits
require_once 'config.php';

/**
 * Pay direct-referral commission to the sponsor of the purchasing user.
 * The commission rate is limited by the sponsor's highest purchased package.
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
     * 3. Get the buyer's package details
     *------------------------------------------------*/
    $buyerPkgStmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.referral_rate 
        FROM packages p
        JOIN wallet_tx wt ON p.id = wt.package_id
        WHERE wt.user_id = ? AND wt.type = 'package' 
        ORDER BY wt.id DESC 
        LIMIT 1
    ");
    $buyerPkgStmt->execute([$buyerId]);
    $buyerPkg = $buyerPkgStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$buyerPkg) {
        // No package found for buyer, cannot calculate referral
        return;
    }

    /*-------------------------------------------------
     * 4. Get sponsor's highest purchased package
     *------------------------------------------------*/
    $sponsorPkgStmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.referral_rate 
        FROM packages p
        JOIN wallet_tx wt ON p.id = wt.package_id
        WHERE wt.user_id = ? AND wt.type = 'package' 
        ORDER BY p.price DESC 
        LIMIT 1
    ");
    $sponsorPkgStmt->execute([$sponsorId]);
    $sponsorPkg = $sponsorPkgStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sponsorPkg) {
        // Sponsor has no package purchased, cannot receive referral bonus
        return;
    }

    /*-------------------------------------------------
     * 5. Determine the applicable referral rate
     * Use the sponsor's package rate, but cap the commission
     * based on sponsor's package price if buyer's package is higher
     *------------------------------------------------*/
    $referralRate = (float)$sponsorPkg['referral_rate'];
    $maxCommissionBase = (float)$sponsorPkg['price']; // Sponsor's package price
    $actualPurchasePrice = $pkgPrice; // Buyer's package price
    
    // Calculate commission based on sponsor's rate
    $baseCommission = $actualPurchasePrice * $referralRate;
    
    // Cap the commission: sponsor cannot earn more than their own package's referral would generate
    $maxAllowedCommission = $maxCommissionBase * $referralRate;
    
    // Final commission is the minimum of base calculation and sponsor's package limit
    $commission = min($baseCommission, $maxAllowedCommission);

    if ($commission <= 0.00) {
        return;
    }

    /*-------------------------------------------------
     * 6. Credit wallet and record transaction
     *------------------------------------------------*/
    // Credit wallet
    $pdo->prepare(
        'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
    )->execute([$commission, $sponsorId]);

    // Record transaction with detailed description
    $description = sprintf(
        'Referral bonus from %s (Buyer Package: %s $%.2f, Sponsor Package: %s $%.2f, Rate: %.1f%%)',
        $buyerId,
        $buyerPkg['name'],
        $actualPurchasePrice,
        $sponsorPkg['name'],
        $maxCommissionBase,
        $referralRate * 100
    );

    $pdo->prepare(
        'INSERT INTO wallet_tx (user_id, type, amount, package_id)
         VALUES (?, "referral_bonus", ?, ?)'
    )->execute([$sponsorId, $commission, $sponsorPkg['id']]);

    /*-------------------------------------------------
     * 7. Optional: Log the referral calculation for debugging
     *------------------------------------------------*/
    error_log(sprintf(
        "Referral Commission Paid: Sponsor ID %d received $%.2f " .
        "(Base: $%.2f, Capped at: $%.2f) for buyer ID %d's $%.2f purchase",
        $sponsorId,
        $commission,
        $baseCommission,
        $maxAllowedCommission,
        $buyerId,
        $actualPurchasePrice
    ));
}

/**
 * Helper function to get user's highest purchased package
 * 
 * @param int $userId
 * @param PDO $pdo
 * @return array|null Package details or null if no package found
 */
function getUserHighestPackage(int $userId, PDO $pdo): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.referral_rate, p.pv
        FROM packages p
        JOIN wallet_tx wt ON p.id = wt.package_id
        WHERE wt.user_id = ? AND wt.type = 'package' 
        ORDER BY p.price DESC 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ?: null;
}

/**
 * Helper function to check if user can receive referral bonuses
 * 
 * @param int $userId
 * @param PDO $pdo
 * @return bool
 */
function canReceiveReferralBonus(int $userId, PDO $pdo): bool
{
    return getUserHighestPackage($userId, $pdo) !== null;
}
?>