# 🧬 Rixile Binary & Referral MLM Platform

**Version:** 1.0  
**License:** MIT-style (free to use / modify)  
**Stack:** PHP 8+, MySQL 8+, D3.js v7, Tailwind CSS, Bootstrap 5  
**Author:** You (auto-generated from full source dump)

---

## 📌 Overview

Rixile is a full-stack **Binary + Unilevel MLM** web application that supports:

- **Binary Tree** – strict left / right placement with daily pair bonuses
- **Direct Referrals** – 10 % on every package purchase
- **Leadership Bonus** – up to 5 % on 1-5 level downline pairs (PVT/GVT gated)
- **Mentor (Reverse Leadership) Bonus** – ancestor pays 1-5 levels deep (PVT/GVT gated)
- **USDT Wallet** – top-up / withdraw / transfer, admin approval flow
- **Admin Panel** – user list (paginated, searchable), e-wallet queue, settings stub
- **Responsive Charts** – collapsible D3.js binary & sponsorship trees, zoom/pan

---

## 🚀 Quick Start

1.  **Prerequisites**

    ```bash
    PHP ≥ 8.1, MySQL ≥ 8.0, Composer (optional)
    ```

2.  **Install**

    ```bash
    git clone <repo>
    cd rizile
    # Import DB
    mysql -u root -p < schema.sql
    # Or use the one-liner reset script
    php reset.php
    ```

3.  **Web-server**
    Point vhost or `php -S localhost:8000` to the repo root.

4.  **Login**
    ```
    URL: http://localhost:8000
    Admin: admin / admin123   (created by reset.php)
    ```

---

## 🗂️ File Map

| Path                                      | Purpose                            |
| ----------------------------------------- | ---------------------------------- |
| `index.php`                               | Landing page                       |
| `login.php`, `register.php`, `logout.php` | Auth                               |
| `config.php`                              | PDO + constants                    |
| `dashboard.php`                           | SPA shell, route guard, CSRF       |
| `binary_calc.php`                         | Daily pair commission, flush logic |
| `referral_calc.php`                       | 10 % direct bonus                  |
| `leadership_calc.php`                     | 5-level leadership (upline earns)  |
| `leadership_reverse_calc.php`             | 5-level mentor (downline earns)    |
| `functions.php`                           | PVT / GVT helpers                  |
| `reset.php`, `reset_pairs.php`            | Dev helpers                        |
| `binary_tree.php`, `indirect_tree.php`    | Stand-alone D3 charts              |
| `pages/*.php`                             | Dashboard fragments                |
| `css/style.css`, `js/chart-functions.js`  | Shared styles & D3 wrappers        |
| `schema.sql`                              | Full DB schema                     |

---

## 🧮 Commission Rules

| Bonus          | Trigger           | Rate              | Depth   | Requirements              |
| -------------- | ----------------- | ----------------- | ------- | ------------------------- |
| **Pair**       | Binary PV match   | 20 % per pair     | n/a     | Daily cap 10 pairs / user |
| **Referral**   | Package purchase  | 10 %              | 1 level | None                      |
| **Leadership** | Any downline pair | 5 % → 1 % (L1-L5) | 5       | PVT / GVT thresholds      |
| **Mentor**     | Ancestor’s pair   | 3 % → 1 % (L1-L5) | 5       | PVT / GVT thresholds      |

Flushed amounts are logged to prevent re-payout.

---

## 📊 Database Schema (condensed)

- `users` – id, username, password, sponsor_id, upline_id, position, left_count, right_count, pairs_today, role
- `wallets` – user_id PK, balance
- `wallet_tx` – all monetary events
- `packages` – price & PV
- `flushes` – overflow / requirement failures
- `ewallet_requests` – top-up / withdraw queue
- `leadership_flush_log`, `mentor_flush_log` – anti-double-pay

See `schema.sql` for full DDL + indexes.

---

## 🔐 Security Notes

- CSRF tokens on every POST
- PDO prepared statements everywhere
- Passwords hashed with `PASSWORD_DEFAULT`
- Admin-only routes enforced in-dashboard and in fragments
- XSS filtered via `htmlspecialchars`

---

## 🎨 Branding & Theming

- Dark charts (`#0b1020`) – see `binary_tree.php`
- Light dashboard – Tailwind CDN
- Swap colors in `css/style.css` variables or `config.php` constants.

---

## 🧪 Developer Tips

1.  **Reset daily pairs**  
    `php reset_pairs.php`
2.  **Re-seed DB**  
    `php reset.php`
3.  **Test binary flush**  
    Insert PV on leaf, watch `flushes` table.
4.  **Chart debugging**  
    Open browser console – `initBinaryChart()` & `initSponsorChart()` auto-load on respective pages.

---

## 📈 Roadmap Ideas

- KYC uploads
- Auto-withdraw via TRC-20 API
- Rank badges & e-commerce store
- REST API endpoints
- React / Vue front-end refactor

---

Enjoy building & scaling your MLM network!
