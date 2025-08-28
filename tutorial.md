──────────────────────────────  
THE RIXILE BONUS SYSTEM – “LEGO-BLOCK” STYLE  
(Scroll slowly – every sentence is a step you can test in the playground)  
──────────────────────────────

0.  VOCABULARY FIRST  
    • YOU = the person who just bought a package.  
    • PV = Point Value (always = package price in USDT).  
    • Sponsor = the person who referred YOU.  
    • Upline = the person whose left / right leg YOU occupy (binary).  
    • Downline = everyone who is under you in either tree.  
    • PVT = Personal Volume Total = money you personally spent on packages.  
    • GVT = Group Volume Total = PVT of you + every downline in the sponsorship tree (unlimited depth).

──────────────────────────────

1.  REFERRAL BONUS – “DIRECT CASH”  
    ──────────────────────────────  
    Rule: 10 % of every package price goes **immediately** to the sponsor.  
    Example:  
    • You buy “Pro” package = 50 USDT.  
    • Your sponsor instantly receives 50 × 10 % = 5 USDT in his wallet.  
    That is the end of this bonus – no levels, no legs, no limits.

──────────────────────────────  
2. BINARY BONUS – “DAILY PAIRS”  
──────────────────────────────  
Visual: imagine your binary tree as two buckets – LEFT and RIGHT.

Step-by-step for a NEW package purchase:

1.  The PV of the package is **added** to every ancestor’s bucket (left or right, depending on where you sit).
2.  Each ancestor can count **one pair** when his two buckets are **both ≥ 1 PV**.
3.  One pair = 1 USDT × 20 % = 0.20 USDT paid to the ancestor.
4.  After the pair is paid, the matched PV is **removed** from BOTH buckets (flushed).
5.  Daily cap = **10 pairs per person** (DAILY_MAX). Extra pairs are stored in the `flushes` table with reason “binary_overflow”.

Tiny example with 3 people  
• A (you) – buys 50 PV → placed in your own buckets (not used for pairs).  
• B – buys 20 PV left leg of A.  
• C – buys 30 PV right leg of A.  
Now A’s buckets: Left = 20, Right = 30 → min = 20 → A earns 20 × 0.20 = 4 USDT today.  
20 PV is flushed from both legs.  
Left bucket becomes 0, Right bucket becomes 10.

Reset at 00:00 daily: `php reset_pairs.php` sets `pairs_today = 0` for everyone.

──────────────────────────────  
3. LEADERSHIP BONUS – “GET PAID FROM YOUR DOWNLINE’S PAIRS”  
──────────────────────────────  
Who pays? **Each ancestor** above the person who earned the binary pair.  
Who receives? **You** – if you are an ancestor of the pair earner.  
How much? Depends on **your level** and **your PVT / GVT**.

Level schedule (per pair earned by downline):  
| Level | % of pair | YOU need PVT | YOU need GVT |  
|-------|-----------|--------------|--------------|  
| 1 | 5 % | 100 USDT | 500 USDT |  
| 2 | 4 % | 200 USDT | 1 000 USDT |  
| 3 | 3 % | 300 USDT | 2 500 USDT |  
| 4 | 2 % | 500 USDT | 5 000 USDT |  
| 5 | 1 % | 1 000 USDT | 10 000 USDT |

Important mechanics  
• The system starts from the **pair earner** and walks **UP** the sponsorship line.  
• For each ancestor it checks the table above.  
• If the ancestor **misses** the requirement, the money is **flushed** and **logged** – he will never be paid again for that (ancestor, level, earner) combination.

Concrete mini-story  
Assume:

- You have 1 200 PVT and 12 000 GVT.
- Your level-3 downline “Emma” earns a 1 USDT pair.

Calculation:

1.  Emma → her sponsor (level-1) – check requirements.
2.  If sponsor qualifies → he gets 5 % (0.05 USDT).
3.  Sponsor’s sponsor (level-2) – check – if qualifies → 4 % (0.04 USDT).
4.  **You** are level-3 → check: 1 200 ≥ 300 and 12 000 ≥ 2 500 → yes → you get 3 % (0.03 USDT).
5.  Levels 4-5 continue upward if they qualify.
6.  Any ancestor who fails **loses** that slice forever.

──────────────────────────────  
4. LEADERSHIP_REVERSE (MENTOR) BONUS – “PAY YOUR DOWNLINE”  
──────────────────────────────  
This is the **mirror image** of Leadership.

Who pays? **You** – when **you** earn a binary pair.  
Who receives? **Your downline** 1-5 levels deep.  
How much? Depends on **their level** and **their PVT / GVT**.

Level schedule (same numbers, but taken from the **downline’s** stats):

| Level below you | % of YOUR pair | Downline needs PVT | Downline needs GVT |
| --------------- | -------------- | ------------------ | ------------------ |
| 1               | 3 %            | 100 USDT           | 500 USDT           |
| 2               | 2.5 %          | 200 USDT           | 1 000 USDT         |
| 3               | 2 %            | 300 USDT           | 2 500 USDT         |
| 4               | 1.5 %          | 500 USDT           | 5 000 USDT         |
| 5               | 1 %            | 1 000 USDT         | 10 000 USDT        |

Mini-story

- You earn a 1 USDT pair.
- Your level-2 downline “Carlos” has 250 PVT and 1 200 GVT.
- Check Carlos requirements: 250 ≥ 200 and 1 200 ≥ 1 000 → OK.
- Carlos instantly receives 1 USDT × 2.5 % = 0.025 USDT.
- If Carlos failed, the money is flushed and **logged** so Carlos never gets it later.

──────────────────────────────  
5. ONE COMPLETE FLOW (5 PEOPLE)  
──────────────────────────────  
People: Admin (root), Alice (sponsor), Bob (Alice’s left), Carol (Alice’s right), Dave (Bob’s left).

Day 1

1.  Alice buys “Elite” 100 PV.  
    • No referral (she is first).  
    • Her PV → up the binary legs (only Admin sees 100 left).
2.  Bob buys “Starter” 25 PV left under Alice.  
    • Alice earns 25 × 10 % = 2.5 USDT referral.  
    • Alice’s left bucket = 25.
3.  Carol buys “Pro” 50 PV right under Alice.  
    • Alice earns 50 × 10 % = 5 USDT referral.  
    • Alice now has Left = 25, Right = 50 → 25 pairs → 25 × 0.20 = 5 USDT binary.  
    • Alice’s buckets flush to 0 and 25.

Day 2  
4. Dave buys “Elite” 100 PV left under Bob.  
 • Bob earns 100 × 10 % = 10 USDT referral.  
 • Binary propagation:  
 – Alice left bucket += 100 → 25 + 100 = 125.  
 – Alice right bucket still 25.  
 – New pairs = 25 → Alice earns another 5 USDT.  
 – Leadership: Admin looks 1-5 levels up **from Alice** (none exist) → nothing.  
 – Mentor: Bob (level-1 downline from Alice) checks his stats. If Bob qualifies he gets 3 % of Alice’s 5 USDT pair = 0.15 USDT.

──────────────────────────────  
6. CHEAT-SHEET FOR PROSPECTS  
──────────────────────────────

1.  Join free → choose sponsor & binary placement (left / right).
2.  Buy any package → immediately:  
    • Sponsor gets 10 % cash.  
    • Your PV starts climbing the binary tree.
3.  Build two legs → daily pair cash (max 10 pairs).
4.  Boost PVT & GVT to unlock **Leadership** & **Mentor** streams.
5.  Use the **Wallet** page to top-up / withdraw / transfer USDT.

──────────────────────────────  
7. ADMIN TEST CHECKLIST  
──────────────────────────────  
☐ Run `php reset.php` → admin/admin123  
☐ Register Alice under admin  
☐ Alice buys 100 PV → check wallet_tx for:  
 • admin +10 USDT (referral)  
 • admin +20 USDT (binary pair)  
☐ Register Bob under Alice, left leg, 25 PV → Alice gets 2.5 referral + 5 binary  
☐ Register Carol under Alice, right leg, 50 PV → Alice gets 5 referral + 5 binary  
☐ `SELECT * FROM flushes` should show any flushed amounts.

You now have the entire bonus engine in your pocket – teach it step-by-step to every new prospect exactly as written above.
