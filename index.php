<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Shoppe Club – the world’s first Buy-&-Build marketplace. Shop daily essentials, earn up to 30 % crypto-cashback, and auto-grow a binary-powered affiliate tree.">
    <title>Shoppe Club – Shopping is the New Mining</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            --secondary-gradient: linear-gradient(135deg, #10b981 0%, #047857 100%);
            --accent-gradient: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            --dark-gradient: linear-gradient(135deg, #0c0c0c 0%, #1a1a1a 100%);
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --text-primary: #1a1a1a;
            --text-secondary: #666;
            --surface: #ffffff;
            --surface-soft: #eff6ff;
            --border-light: rgba(0, 0, 0, 0.08);
            --shadow-soft: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-medium: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-large: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: var(--text-primary);
            background: var(--surface-soft);
            overflow-x: hidden;
        }

        /* Smooth Scrolling */
        html {
            scroll-behavior: smooth;
        }

        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-light);
            z-index: 1000;
            transition: all 0.3s ease;
            padding: 1rem 0;
        }

        .navbar.scrolled {
            background: rgba(255, 255, 255, 0.98);
            box-shadow: var(--shadow-soft);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.8rem;
            font-weight: 800;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
            letter-spacing: -0.02em;
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-gradient);
            transition: width 0.3s ease;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .nav-auth {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .nav-login {
            color: var(--text-primary) !important;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            background: transparent;
            text-decoration: none;
        }

        .nav-login:hover {
            border-color: #3b82f6;
            color: #3b82f6 !important;
            text-decoration: none;
        }

        .nav-login::after {
            display: none;
        }

        .nav-register {
            background: var(--primary-gradient);
            color: white !important;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-soft);
            text-decoration: none;
        }

        .nav-register:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
            text-decoration: none;
        }

        .nav-register::after {
            display: none;
        }

        .mobile-menu {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-primary);
            cursor: pointer;
        }

        /* Return to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: var(--shadow-large);
            z-index: 999;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px);
        }

        .back-to-top.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .back-to-top:hover {
            transform: translateY(-5px) scale(1.1);
            box-shadow: 0 15px 35px rgba(59, 130, 246, 0.4);
        }

        .back-to-top:active {
            transform: translateY(-2px) scale(1.05);
        }

        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 50%, #10b981 100%);
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
            opacity: 0.3;
        }

        .hero-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .hero-content h1 {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.1;
            color: white;
            margin-bottom: 1.5rem;
            letter-spacing: -0.02em;
        }

        .hero-content p {
            font-size: 1.25rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .hero-cta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 1rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: white;
            color: #3b82f6;
            box-shadow: var(--shadow-medium);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-large);
        }

        .btn-secondary {
            background: transparent;
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(20px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .hero-visual {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .floating-cards {
            position: relative;
            width: 100%;
            height: 400px;
        }

        .floating-card {
            position: absolute;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 1.5rem;
            color: white;
            animation: float 6s ease-in-out infinite;
        }

        .floating-card:nth-child(1) {
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }

        .floating-card:nth-child(2) {
            top: 40%;
            right: 10%;
            animation-delay: -2s;
        }

        .floating-card:nth-child(3) {
            bottom: 20%;
            left: 20%;
            animation-delay: -4s;
        }

        .floating-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        /* Features Section */
        .features {
            padding: 6rem 0;
            background: var(--surface);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-header h2 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }

        .section-header p {
            font-size: 1.125rem;
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background: var(--surface);
            border-radius: 20px;
            padding: 2rem;
            text-align: center;
            border: 1px solid var(--border-light);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .feature-card:hover::before {
            transform: scaleX(1);
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-large);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: var(--primary-gradient);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
            color: white;
        }

        .feature-card:nth-child(2) .feature-icon {
            background: var(--secondary-gradient);
        }

        .feature-card:nth-child(3) .feature-icon {
            background: var(--accent-gradient);
        }

        .feature-card:nth-child(4) .feature-icon {
            background: var(--primary-gradient);
        }

        .feature-card h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .feature-card p {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* How It Works Section */
        .how-it-works {
            padding: 6rem 0;
            background: var(--surface-soft);
            position: relative;
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 3rem;
            margin-top: 4rem;
        }

        .step-card {
            text-align: center;
            position: relative;
        }

        .step-number {
            width: 80px;
            height: 80px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin: 0 auto 1.5rem;
            position: relative;
        }

        .step-card:nth-child(2) .step-number {
            background: var(--secondary-gradient);
        }

        .step-card:nth-child(3) .step-number {
            background: var(--accent-gradient);
        }

        .step-card:not(:last-child) .step-number::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 100px;
            height: 2px;
            background: linear-gradient(90deg, #3b82f6, transparent);
            transform: translateY(-50%);
        }

        /* Benefits Section */
        .benefits {
            padding: 6rem 0;
            background: var(--surface);
        }

        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-top: 4rem;
        }

        .benefit-item {
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            padding: 2rem;
            background: var(--surface);
            border-radius: 15px;
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .benefit-item:hover {
            box-shadow: var(--shadow-medium);
        }

        .benefit-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-gradient);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            flex-shrink: 0;
        }

        .benefit-item:nth-child(2) .benefit-icon {
            background: var(--secondary-gradient);
        }

        .benefit-item:nth-child(3) .benefit-icon {
            background: var(--accent-gradient);
        }

        .benefit-item:nth-child(4) .benefit-icon {
            background: var(--primary-gradient);
        }

        /* CTA Section */
        .cta-section {
            padding: 6rem 0;
            background: var(--dark-gradient);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="2" fill="rgba(255,255,255,0.1)"/></svg>') repeat;
            opacity: 0.3;
        }

        .cta-content {
            position: relative;
            z-index: 2;
        }

        .cta-section h2 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            letter-spacing: -0.02em;
        }

        .cta-section p {
            font-size: 1.25rem;
            opacity: 0.9;
            margin-bottom: 2.5rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Footer */
        .footer {
            background: #0a0a0a;
            color: white;
            padding: 3rem 0 1rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            margin-bottom: 2rem;
        }

        .footer-section h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section ul li {
            margin-bottom: 0.5rem;
        }

        .footer-section a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-section a:hover {
            color: white;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 2rem;
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-on-scroll {
            opacity: 0;
            animation: fadeInUp 0.6s ease forwards;
        }

        /* Mobile Styles */
        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }
            
            .mobile-menu {
                display: block;
            }

            .nav-auth {
                gap: 0.5rem;
            }

            .nav-login, .nav-register {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }

            .hero-container {
                grid-template-columns: 1fr;
                gap: 3rem;
                text-align: center;
            }

            .hero-content h1 {
                font-size: 2.5rem;
            }

            .hero-cta {
                justify-content: center;
            }

            .floating-cards {
                height: 300px;
            }

            .section-header h2 {
                font-size: 2rem;
            }

            .cta-section h2 {
                font-size: 2rem;
            }

            .step-card:not(:last-child) .step-number::after {
                display: none;
            }

            .back-to-top {
                bottom: 20px;
                right: 20px;
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .nav-container {
                padding: 0 1rem;
            }

            .nav-auth {
                flex-direction: column;
                gap: 0.5rem;
            }

            .nav-login, .nav-register {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
        }
    </style>
</head>

<body>
<!-- ============ NAVBAR (only text + logo changed) ============ -->
<nav class="navbar" id="navbar">
    <div class="nav-container">
        <a href="#" class="logo">Shoppe Club</a>
        <ul class="nav-links">
            <li><a href="#home">Home</a></li>
            <li><a href="#features">Features</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#benefits">Benefits</a></li>
        </ul>
        <div class="nav-auth">
            <a href="login.php" class="nav-login">Login</a>
            <a href="register.php" class="nav-register">Register</a>
        </div>
        <button class="mobile-menu"><i class="fas fa-bars"></i></button>
    </div>
</nav>

<!-- ======= BACK-TO-TOP (unchanged behaviour) ======= -->
<button class="back-to-top" id="backToTop"><i class="fas fa-arrow-up"></i></button>

<!-- ================= HERO SECTION ================= -->
<section class="hero" id="home">
    <div class="hero-container">
        <div class="hero-content">
            <h1>Shopping is the New Mining</h1>
            <p>Buy groceries, skincare, NFTs, phone top-ups—every $1 spent becomes PV that climbs your binary tree and pays you while you sleep.</p>
            <div class="hero-cta">
                <a href="register.php" class="btn btn-primary"><i class="fas fa-rocket"></i>Start My Club</a>
                <a href="#how-it-works" class="btn btn-secondary"><i class="fas fa-play"></i>See How</a>
            </div>
        </div>

        <!-- new floating cards -->
        <div class="hero-visual">
            <div class="floating-cards">
                <div class="floating-card">
                    <i class="fa-solid fa-bag-shopping"></i>
                    <h4>30 % Crypto-Cashback</h4>
                    <p>On every cart</p>
                </div>
                <div class="floating-card">
                    <i class="fa-solid fa-sitemap"></i>
                    <h4>Binary Bonus</h4>
                    <p>20 % per pair</p>
                </div>
                <div class="floating-card">
                    <i class="fa-solid fa-gift"></i>
                    <h4>Leadership & Mentor</h4>
                    <p>5-level deep</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= FEATURES ================= -->
<section class="features" id="features">
    <div class="container">
        <div class="section-header">
            <h2>Why Shoppe Club?</h2>
            <p>One cart, three income streams, zero extra effort—built for the creator economy & Web3 reality.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card animate-on-scroll">
                <div class="feature-icon"><i class="fa-solid fa-bag-shopping"></i></div>
                <h3>Shop & Earn PV</h3>
                <p>Every $1 = 1 PV. PV flows up your binary legs, triggering daily pairs and cashback—no mining rigs, no coupons to clip.</p>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-icon"><i class="fa-solid fa-link"></i></div>
                <h3>Link-in-Bio Franchise</h3>
                <p>Drop your unique .club handle on TikTok, IG, Discord. Friends shop → you earn 10 % referral + binary pairs instantly.</p>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-icon"><i class="fa-solid fa-chart-pie"></i></div>
                <h3>Real-Time Dashboard</h3>
                <p>Track cashback, pairs, leadership & mentor flows live. One-click swap earnings to USDT, USD, or store credit.</p>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-icon"><i class="fa-solid fa-leaf"></i></div>
                <h3>Carbon-Neutral Cart</h3>
                <p>1 % of every order funds DAO-voted eco-suppliers. Shop responsibly without lifting a finger.</p>
            </div>
        </div>
    </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section class="how-it-works" id="how-it-works">
    <div class="container">
        <div class="section-header">
            <h2>How It Works</h2>
            <p>From checkout to cash-out in 3 Lego-style steps—no webinars, no spreadsheets.</p>
        </div>
        <div class="steps-grid">
            <div class="step-card animate-on-scroll">
                <div class="step-number">1</div>
                <h3>Join Free, Grab Your Handle</h3>
                <p>Pick a sponsor (or let TikTok auto-assign). Get <code>yourname.club</code>—your forever store URL.</p>
            </div>
            <div class="step-card animate-on-scroll">
                <div class="step-number">2</div>
                <h3>Shop or Share—Both Count</h3>
                <p>Buy groceries OR share your link. Either action drops PV into your binary buckets and fires 10 % referral to your sponsor.</p>
            </div>
            <div class="step-card animate-on-scroll">
                <div class="step-number">3</div>
                <h3>Wake Up to Earnings</h3>
                <p>AI matches left/right legs at 00:00 UTC, pays 20 % per pair, tops up cashback, and flushes the rest to tomorrow—automatically.</p>
            </div>
        </div>
    </div>
</section>

<!-- ================= BENEFITS ================= -->
<section class="benefits" id="benefits">
    <div class="container">
        <div class="section-header">
            <h2>Grow Your Wealth with Confidence</h2>
            <p>Built on battle-tested Rixile mechanics, wrapped in a 2025-ready ecommerce shell.</p>
        </div>
        <div class="benefits-grid">
            <div class="benefit-item animate-on-scroll">
                <div class="benefit-icon"><i class="fas fa-shield-alt"></i></div>
                <div>
                    <h4>SAFU by Design</h4>
                    <p>Shopify checkout + Fireblocks custody + on-chain BSC hashes for every bonus. Your cart is safer than your bank.</p>
                </div>
            </div>
            <div class="benefit-item animate-on-scroll">
                <div class="benefit-icon"><i class="fas fa-rocket"></i></div>
                <div>
                    <h4>Instant Liquidity</h4>
                    <p>Cash out USDT to Apple Pay, Google Pay, or bank card in < 30 seconds—no minimums, no stags.</p>
                </div>
            </div>
            <div class="benefit-item animate-on-scroll">
                <div class="benefit-icon"><i class="fas fa-chart-line"></i></div>
                <div>
                    <h4>Bear-Market Proof</h4>
                    <p>Cashback is USDT-denominated. If crypto crashes, flip payout to USD or store credit—your choice, every order.</p>
                </div>
            </div>
            <div class="benefit-item animate-on-scroll">
                <div class="benefit-icon"><i class="fas fa-headset"></i></div>
                <div>
                    <h4>24/7 Support & DAO</h4>
                    <p>Live chat + Discord DAO votes on new product lines. Next up: lab-grown coffee—members decide.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= CTA ================= -->
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <h2>Ready to Turn Receipts into Revenue?</h2>
            <p>Join the wait-list, get 100 PV airdrop + lifetime 0 % marketplace fees. Your first cashback hit lands in 60 seconds.</p>
            <a href="register.php" class="btn btn-primary"><i class="fas fa-star"></i>Join Shoppe Club Now</a>
        </div>
    </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section">
                <h3>Shoppe Club</h3>
                <p>Shopping is the new mining. Turn every cart into a mini-franchise—secure, transparent, eco-forward.</p>
            </div>
            <div class="footer-section">
                <h3>Explore</h3>
                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#how-it-works">How It Works</a></li>
                    <li><a href="#benefits">Benefits</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h3>Support</h3>
                <ul>
                    <li><a href="#faq">FAQ</a></li>
                    <li><a href="#contact">Contact Us</a></li>
                    <li><a href="#privacy">Privacy Policy</a></li>
                    <li><a href="#terms">Terms of Service</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h3>Account</h3>
                <ul>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php">Register</a></li>
                    <li><a href="dashboard.php">Dashboard</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2025 Shoppe Club. All Rights Reserved. Built on Rixile’s proven engine, re-imagined for ecommerce.</p>
        </div>
    </div>
</footer>

<script>
	// Navbar scroll effect
	window.addEventListener('scroll', function() {
		const navbar = document.getElementById('navbar');
		if (window.scrollY > 50) {
			navbar.classList.add('scrolled');
		} else {
			navbar.classList.remove('scrolled');
		}
	});

	// Back to top button functionality
	const backToTopBtn = document.getElementById('backToTop');
	
	window.addEventListener('scroll', function() {
		if (window.pageYOffset > 300) {
			backToTopBtn.classList.add('show');
		} else {
			backToTopBtn.classList.remove('show');
		}
	});

	backToTopBtn.addEventListener('click', function() {
		window.scrollTo({
			top: 0,
			behavior: 'smooth'
		});
	});

	// Animate elements on scroll
	const observerOptions = {
		threshold: 0.1,
		rootMargin: '0px 0px -50px 0px'
	};

	const observer = new IntersectionObserver(function(entries) {
		entries.forEach(entry => {
			if (entry.isIntersecting) {
				entry.target.style.animationDelay = Math.random() * 0.3 + 's';
				entry.target.classList.add('animate-on-scroll');
				observer.unobserve(entry.target);
			}
		});
	}, observerOptions);

	document.querySelectorAll('.feature-card, .step-card, .benefit-item').forEach(el => {
		observer.observe(el);
	});

	// Smooth scroll for navigation links
	document.querySelectorAll('a[href^="#"]').forEach(anchor => {
		anchor.addEventListener('click', function (e) {
			e.preventDefault();
			const target = document.querySelector(this.getAttribute('href'));
			if (target) {
				target.scrollIntoView({
					behavior: 'smooth',
					block: 'start'
				});
			}
		});
	});

	// Mobile menu toggle
	document.querySelector('.mobile-menu').addEventListener('click', function() {
		const navLinks = document.querySelector('.nav-links');
		navLinks.style.display = navLinks.style.display === 'flex' ? 'none' : 'flex';
	});

	// Add parallax effect to hero section
	window.addEventListener('scroll', function() {
		const scrolled = window.pageYOffset;
		const hero = document.querySelector('.hero');
		const rate = scrolled * -0.5;
		hero.style.transform = `translateY(${rate}px)`;
	});

	// Add loading animation
	window.addEventListener('load', function() {
		document.body.classList.add('loaded');
	});

	// Mobile menu toggle functionality
	document.querySelector('.mobile-menu').addEventListener('click', function() {
		const navLinks = document.querySelector('.nav-links');
		navLinks.classList.toggle('show');
	});
</script>
</body>
</html>