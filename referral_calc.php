<?php
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
        'SELECT sponsor_name FROM users WHERE id = ?'
    );
    $stmt->execute([$buyerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['sponsor_name'])) {
        // No sponsor → nothing to pay
        return;
    }

    /*-------------------------------------------------
     * 2. Resolve sponsor's user-id
     *------------------------------------------------*/
    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE username = ?'
    );
    $stmt->execute([$row['sponsor_name']]);
    $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sponsor) {
        // Sponsor username not found (edge-case)
        return;
    }

    $sponsorId = (int) $sponsor['id'];

    /*-------------------------------------------------
     * 3. Calculate & credit commission
     *------------------------------------------------*/
    $commission = $pkgPrice * REFERRAL_RATE;   // already a % from config.php

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