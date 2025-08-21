# 🚀 Sanitarium System

A complete **Binary Multi-Level Marketing (MLM)** system with **pairing bonuses, matched bonuses, mentor bonuses, and a dual-mode e-wallet system** that uses **USDT (BEP20)** as the internal currency, and a proprietary token — **Binance Token Payments (B2P)** — for top-ups and withdrawals.

---

## 🌐 System Overview

This system allows:

- Binary tree structure (Left & Right legs).
- Earnings from **pairing bonuses**.
- Additional income streams: **Matched Bonus** and **Mentor Bonus**.
- E-wallet operations powered by **USDT (BEP20)**, with settlements via **B2P (Binance Token Payments)**.
- **Two transaction modes**: admin-mediated and fully on-chain automatic.

---

## 🌳 Binary Structure

- Each account may have **two direct downlines**:

  - **Left leg**
  - **Right leg**

- All new members must choose:

  - **Upline username**
  - **Position** (Left/Right)

**Pairing Bonus:**

- Earned when **both left and right legs** register new volume.
- Pairs are counted daily.
- Example: 1 left + 1 right = 1 pair.

---

## 🎁 Bonus Types

### 1️⃣ Pairing Bonus

- Core earning from binary tree.
- Each completed left-right pair adds income to the user’s e-wallet (in USDT).

---

### 2️⃣ Matched Bonus (Downline Earnings)

- Earned from **downline binary earnings**, up to **5 levels** deep.
- To unlock each level, user must meet **two requirements**:

  - **Personal Volume Target (PVT)** → Direct purchases by the user.
  - **Group Volume Target (GVT)** → Collective purchases from the user’s team.

- Each level has **unique requirements** and **unique payout %**.

#### 📊 Example Schedule (Matched Bonus)

| Level | PVT Requirement | GVT Requirement | % of Downline Binary Earnings |
| ----- | --------------- | --------------- | ----------------------------- |
| 1     | 100 USDT        | 500 USDT        | 5%                            |
| 2     | 200 USDT        | 1,000 USDT      | 4%                            |
| 3     | 300 USDT        | 2,500 USDT      | 3%                            |
| 4     | 500 USDT        | 5,000 USDT      | 2%                            |
| 5     | 1,000 USDT      | 10,000 USDT     | 1%                            |

---

### 3️⃣ Mentor Bonus (Upline Earnings)

- Works as a **reverse matched bonus**.
- User earns from **uplines’ binary earnings**, up to **5 levels above**.
- Same **PVT + GVT unlocking rules** apply.
- Percentages are also **unique per level**.

#### 📊 Example Schedule (Mentor Bonus)

| Level (Above) | PVT Requirement | GVT Requirement | % of Upline Binary Earnings |
| ------------- | --------------- | --------------- | --------------------------- |
| 1             | 100 USDT        | 500 USDT        | 3%                          |
| 2             | 200 USDT        | 1,000 USDT      | 2.5%                        |
| 3             | 300 USDT        | 2,500 USDT      | 2%                          |
| 4             | 500 USDT        | 5,000 USDT      | 1.5%                        |
| 5             | 1,000 USDT      | 10,000 USDT     | 1%                          |

---

## 💳 E-Wallet & Tokenization System

### Main Currency

- **USDT (BEP20)** → Used for all balances, bonuses, and reports.

### Settlement Token

- **Binance Token Payments (B2P)**

  - Contract: `0xf8ab9ff465c612d5be6a56716adf95c52f8bc72d`
  - All top-ups and withdrawals are settled via B2P.
  - Conversion between **USDT ↔ B2P** is automatic.

---

### 🔼 Top-Up (Deposit)

#### **Admin-Mediated Mode**

1. User enters the **top-up amount in USDT**.
2. System shows the **equivalent B2P**.
3. User transfers B2P → Admin’s wallet.
4. User submits **transaction hash**.
5. Admin verifies & approves.
6. System credits USDT balance 1:1 in e-wallet.

#### **On-Chain Automatic Mode**

1. User enters desired **top-up amount in USDT**.
2. System shows equivalent **B2P**.
3. User authorizes blockchain transfer.
4. Smart contract transfers **B2P → system escrow wallet**.
5. User’s e-wallet balance (USDT) updates instantly.
6. **No admin involvement required**.

---

### 🔽 Withdrawal (Payout)

#### **Admin-Mediated Mode**

1. User enters withdrawal in **USDT**.
2. System shows equivalent **B2P**.
3. Request is sent to Admin.
4. Admin transfers **B2P → User’s wallet**.
5. Admin approves withdrawal for record-keeping.

#### **On-Chain Automatic Mode**

1. User enters withdrawal in **USDT**.
2. System calculates equivalent **B2P**.
3. Smart contract transfers **B2P → User’s wallet**.
4. USDT balance in e-wallet decreases automatically.
5. **No admin involvement required**.

---

### ⚙️ E-Wallet Features

- **Balance always shown in USDT** for clarity.
- **Dual modes:** Admin-mediated OR On-chain automatic.
- **Conversion engine:** Handles USDT ↔ B2P.
- **Transaction Hash logs** for audits (both modes).
- **Policy controls:** Admin sets min/max for deposits & withdrawals.

---

## 🔐 Security & Transparency

- All transactions (top-ups, withdrawals) recorded on-chain via B2P.
- System keeps **audit logs** for all operations.
- Admin retains control in mediated mode.
- Users can opt for **full decentralization** via automatic mode.

---

## 📊 Example Flow

1. User purchases a package (meets **Personal Volume Target**).
2. User grows a team (meets **Group Volume Target**).
3. User earns from:

   - Pairing Bonus
   - Matched Bonus (downlines up to 5 levels)
   - Mentor Bonus (uplines up to 5 levels)

4. Earnings reflected in **USDT e-wallet balance**.
5. User chooses **Top-up/Withdrawal mode** (Admin-mediated OR On-chain automatic).
6. Conversion USDT ↔ B2P handled automatically.

---

## 🧮 Calculation Example

Let’s see how the bonuses actually flow with numbers:

### Scenario

- Alice (User A) has Bob (downline, Level 1).
- Bob earns **100 USDT in Pairing Bonus** from his own downlines.
- Alice also has an upline, Carol.

### Step 1: Pairing Bonus

- Bob receives his **100 USDT** Pairing Bonus.

### Step 2: Matched Bonus (Alice earns from Bob)

- Alice qualifies for **Matched Bonus Level 1** (PVT 100 USDT, GVT 500 USDT).
- Alice earns **5% of Bob’s 100 USDT** = **5 USDT**.

### Step 3: Mentor Bonus (Bob earns from Carol)

- Carol (upline above Alice) qualifies for **Mentor Bonus Level 1**.
- Carol earns **3% of Alice’s Binary Earnings**.
- Since Alice just earned 5 USDT from Matched Bonus, Carol earns: **3% of 5 USDT = 0.15 USDT**.

### Step 4: Wallet Reflection

- Bob’s wallet: **+100 USDT**
- Alice’s wallet: **+5 USDT**
- Carol’s wallet: **+0.15 USDT**

All balances stored in **USDT**, but actual withdrawals happen via **B2P** token.

---

## 📌 Summary

- **Binary Core**: Pairing Bonus.
- **Upline/Downline Earnings**: Matched & Mentor Bonuses (5 levels each).
- **Requirements**: Separate **PVT + GVT** per bonus, per level.
- **E-Wallet**: USDT-based, B2P-settled.
- **Transaction Options**:

  - Admin-mediated (manual verify & approve).
  - On-chain automatic (smart contract execution).

- **Transparency**: All payouts auditable in both system & blockchain.
