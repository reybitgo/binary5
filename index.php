<?php
// index.php - Modern, responsive e-commerce landing page for Rixile
require 'config.php';
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Join Rixile's innovative bonus system to earn rewards through referrals, binary pairs, and more. Start your financial journey today!">
    <title>Rixile - Unlock Your Financial Potential</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        /* Mobile-first base styles */
        body {
            background: #f8f9fa;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            font-size: 16px;
        }

        .container {
            max-width: 100%;
            padding: 1rem;
            margin: 0 auto;
        }

        /* Navigation */
        .header-main {
            background: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
            padding: 1rem 0;
        }

        .header-logo a {
            font-size: 1.8rem;
            font-weight: 700;
            color: rgb(59, 130, 246);
            text-decoration: none;
            background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(34, 197, 94) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .header-menu {
            display: none;
        }

        .header-menu.active {
            display: block;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            padding: 1rem;
        }

        .header-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .header-menu li {
            margin: 0.5rem 0;
        }

        .header-menu a {
            color: #333;
            text-decoration: none;
            font-weight: 500;
        }

        .header-menu a:hover {
            color: rgb(59, 130, 246);
        }

        .navbar-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: rgb(59, 130, 246);
        }

        /* Hero Banner */
        .hero-banner {
            background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(34, 197, 94) 100%);
            color: white;
            padding: 3rem 1rem;
            text-align: center;
            border-radius: 15px;
            margin-bottom: 2rem;
        }

        .hero-banner h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .hero-banner p {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }

        .hero-banner img {
            max-width: 100%;
            height: auto;
            margin-top: 1rem;
        }

        /* Sections */
        .section {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .section h2 {
            font-size: 1.8rem;
            color: rgb(59, 130, 246);
            margin-bottom: 1rem;
        }

        .section p {
            font-size: 1rem;
            color: #555;
            line-height: 1.6;
        }

        /* Feature Grid */
        .feature-grid {
            display: grid;
            gap: 1rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            transition: transform 0.3s;
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .feature-card i {
            font-size: 2.5rem;
            color: rgb(34, 197, 94);
            margin-bottom: 1rem;
        }

        .feature-card h3 {
            font-size: 1.2rem;
            color: rgb(34, 197, 94);
            margin-bottom: 0.5rem;
        }

        .feature-card p {
            font-size: 0.9rem;
            color: #666;
        }

        /* CTA Buttons */
        .btn-primary {
            background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(34, 197, 94) 100%);
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 10px;
            transition: transform 0.2s, box-shadow 0.2s;
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(59, 130, 246, 0.4);
        }

        .btn-secondary {
            background: transparent;
            border: 2px solid rgb(34, 197, 94);
            color: rgb(34, 197, 94);
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 10px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-secondary:hover {
            background: rgb(34, 197, 94);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(34, 197, 94, 0.4);
        }

        /* Final CTA Section */
        .cta-section {
            background: linear-gradient(135deg, rgb(34, 98, 187) 0%, rgb(26, 148, 71) 100%);
            color: white;
            padding: 3rem 1rem;
            text-align: center;
            border-radius: 15px;
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
            background: rgba(0, 0, 0, 0.2);
            z-index: 1;
        }

        .cta-section h2, .cta-section p, .cta-section a {
            position: relative;
            z-index: 2;
        }

        .cta-section h2 {
            font-size: 2rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .cta-section p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }

        .cta-section .btn-primary {
            background: white;
            color: rgb(59, 130, 246);
            font-weight: 700;
            padding: 1rem 2rem;
        }

        .cta-section .btn-primary:hover {
            background: rgb(34, 197, 94);
            color: white;
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(34, 197, 94) 100%);
            color: white;
            padding: 2rem 1rem;
            text-align: center;
        }

        .footer-logo a {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            text-decoration: none;
            background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(34, 197, 94) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .footer-links ul {
            list-style: none;
            padding: 0;
            margin: 1rem 0;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            font-weight: 500;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        /* Desktop styles */
        @media (min-width: 768px) {
            .container {
                max-width: 720px;
                padding: 2rem;
            }

            .hero-banner h1 {
                font-size: 2.5rem;
            }

            .hero-banner p {
                font-size: 1.2rem;
            }

            .section h2 {
                font-size: 2rem;
            }

            .feature-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .header-menu {
                display: block;
            }

            .navbar-toggle {
                display: none;
            }

            .header-menu ul {
                display: flex;
                gap: 1.5rem;
            }

            .header-menu li {
                margin: 0;
            }

            .cta-section h2 {
                font-size: 2.5rem;
            }
        }

        @media (min-width: 992px) {
            .container {
                max-width: 960px;
            }

            .feature-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .hero-banner {
                display: flex;
                align-items: center;
                justify-content: space-between;
                text-align: left;
            }

            .hero-banner .text-content {
                flex: 1;
                padding-right: 2rem;
            }

            .hero-banner .image-content {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <header class="header-main">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div class="header-logo">
                    <a href="./">Rixile</a>
                </div>
                <button class="navbar-toggle" onclick="toggleMenu()">
                    <i class="fa fa-bars"></i>
                </button>
                <nav class="header-menu" id="header-menu">
                    <ul class="menu">
                        <li class="menu-item"><a class="menu-link" href="./">Home</a></li>
                        <li class="menu-item"><a class="menu-link" href="#about">About</a></li>
                        <li class="menu-item"><a class="menu-link" href="#faq">FAQ</a></li>
                        <li class="menu-item"><a class="menu-link" href="#contact">Contact</a></li>
                        <li class="menu-item"><a class="menu-link btn btn-secondary" href="login.php">Login</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    </header>

    <main>
        <!-- Hero Banner -->
        <section class="hero-banner fade-in">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-lg-7 text-content">
                        <h1>Unlock Your Financial Potential with Rixile</h1>
                        <p>Join our innovative bonus system and start earning rewards through referrals, binary pairs, and more!</p>
                        <a href="register.php" class="btn btn-primary">Join Now</a>
                    </div>
                    <div class="col-lg-5 image-content text-center">
                        <i class="fa fa-shopping-bag" style="font-size: 8rem; color: white; opacity: 0.8;"></i>
                    </div>
                </div>
            </div>
        </section>

        <!-- Why Rixile -->
        <section class="section fade-in" id="about">
            <div class="container">
                <h2 class="text-center">Why Choose Rixile?</h2>
                <p class="text-center mb-4">Discover a rewarding system designed to help you grow your wealth effortlessly.</p>
                <div class="feature-grid">
                    <div class="feature-card">
                        <i class="fa fa-hand-holding-dollar"></i>
                        <h3>Referral Bonus</h3>
                        <p>Earn 10% instantly on every package purchased by your referrals.</p>
                    </div>
                    <div class="feature-card">
                        <i class="fa fa-sitemap"></i>
                        <h3>Binary Bonus</h3>
                        <p>Build two legs and earn 20% per matched pair, up to 10 pairs daily.</p>
                    </div>
                    <div class="feature-card">
                        <i class="fa fa-trophy"></i>
                        <h3>Leadership Bonus</h3>
                        <p>Get paid from your downline’s pairs based on your PVT and GVT.</p>
                    </div>
                    <div class="feature-card">
                        <i class="fa fa-users"></i>
                        <h3>Mentor Bonus</h3>
                        <p>Share earnings with your downline to foster growth.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- How It Works -->
        <section class="section fade-in" style="background: #f1f3f5;">
            <div class="container">
                <h2 class="text-center">How It Works</h2>
                <p class="text-center mb-4">Start your journey with Rixile in just a few simple steps.</p>
                <div class="row justify-content-center">
                    <div class="col-lg-4 col-md-6">
                        <div class="feature-card">
                            <i class="fa fa-user-plus"></i>
                            <h3>1. Join for Free</h3>
                            <p>Sign up, choose your sponsor, and select your binary placement (left or right).</p>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="feature-card">
                            <i class="fa fa-shopping-cart"></i>
                            <h3>2. Purchase a Package</h3>
                            <p>Buy a package to start earning. Your sponsor gets 10% instantly.</p>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="feature-card">
                            <i class="fa fa-wallet"></i>
                            <h3>3. Earn Rewards</h3>
                            <p>Build your network, earn bonuses, and manage your USDT via the Wallet page.</p>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <a href="register.php" class="btn btn-primary">Get Started</a>
                </div>
            </div>
        </section>

        <!-- Benefits -->
        <section class="section fade-in">
            <div class="container">
                <h2 class="text-center">Grow Your Wealth with Confidence</h2>
                <p class="text-center mb-4">Rixile offers a secure, user-friendly platform to maximize your earnings.</p>
                <div class="row justify-content-center">
                    <div class="col-lg-6">
                        <div class="d-flex align-items-start mb-3 fade-in">
                            <i class="fa fa-shield-alt me-3" style="font-size: 2rem; color: rgb(59, 130, 246);"></i>
                            <div>
                                <h4>Secure Transactions</h4>
                                <p>Manage your USDT with confidence using our secure wallet system.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="d-flex align-items-start mb-3 fade-in">
                            <i class="fa fa-rocket me-3" style="font-size: 2rem; color: rgb(59, 130, 246);"></i>
                            <div>
                                <h4>Fast Rewards</h4>
                                <p>Earn instant bonuses and transfer funds with just a few clicks.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Final CTA -->
        <section class="cta-section fade-in">
            <div class="container text-center">
                <h2>Join the Rixile Community Today</h2>
                <p>Be part of a rewarding system designed to help you achieve financial independence.</p>
                <a href="register.php" class="btn btn-primary">Sign Up Now</a>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-4 text-center">
                    <div class="footer-logo">
                        <a href="./">Rixile</a>
                    </div>
                    <div class="footer-links">
                        <ul>
                            <li><a href="#about">About</a></li>
                            <li><a href="#faq">FAQ</a></li>
                            <li><a href="#contact">Contact</a></li>
                            <li><a href="login.php">Login</a></li>
                            <li><a href="#privacy">Privacy Policy</a></li>
                        </ul>
                    </div>
                    <p class="mt-3">© 2025 Rixile. All Rights Reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleMenu() {
            const menu = document.getElementById('header-menu');
            menu.classList.toggle('active');
        }
    </script>
</body>
</html>