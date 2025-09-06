<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Shoppe Club – the world's first Buy-&-Build marketplace. Shop daily essentials, earn up to 30% crypto-cashback, and auto-grow a binary-powered affiliate tree.">
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
            --surface-soft: #f8fafc;
            --border-light: rgba(0, 0, 0, 0.08);
            --shadow-soft: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-medium: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-large: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --container-max: 1200px;
            --container-padding: clamp(1rem, 4vw, 2rem);
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
            padding: clamp(0.5rem, 2vw, 1rem) 0;
        }

        .navbar.scrolled {
            background: rgba(255, 255, 255, 0.98);
            box-shadow: var(--shadow-soft);
        }

        .nav-container {
            max-width: var(--container-max);
            margin: 0 auto;
            padding: 0 var(--container-padding);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: clamp(1.4rem, 3vw, 1.8rem);
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
            gap: clamp(1rem, 3vw, 2rem);
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 500;
            font-size: clamp(0.9rem, 2vw, 1rem);
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
            gap: 0.75rem;
            align-items: center;
        }

        .nav-login, .nav-register {
            color: var(--text-primary);
            padding: clamp(0.5rem, 2vw, 0.75rem) clamp(1rem, 3vw, 1.5rem);
            border-radius: 50px;
            font-weight: 600;
            font-size: clamp(0.85rem, 2vw, 1rem);
            transition: all 0.3s ease;
            text-decoration: none;
            white-space: nowrap;
        }

        .nav-login {
            border: 2px solid transparent;
            background: transparent;
        }

        .nav-login:hover {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .nav-register {
            background: var(--primary-gradient);
            color: white;
            box-shadow: var(--shadow-soft);
        }

        .nav-register:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .mobile-menu {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-primary);
            cursor: pointer;
            padding: 0.5rem;
        }

        .mobile-nav {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            z-index: 999;
            padding: 2rem;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 2rem;
        }

        .mobile-nav.active {
            display: flex;
        }

        .mobile-nav a {
            font-size: 1.5rem;
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
        }

        .mobile-close {
            position: absolute;
            top: 2rem;
            right: 2rem;
            background: none;
            border: none;
            font-size: 2rem;
            color: var(--text-primary);
            cursor: pointer;
        }

        /* Back to Top Button */
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

        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 50%, #10b981 100%);
            padding: clamp(80px, 15vh, 120px) 0 clamp(60px, 10vh, 80px);
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
            max-width: var(--container-max);
            margin: 0 auto;
            padding: 0 var(--container-padding);
            display: grid;
            grid-template-columns: 1fr;
            gap: clamp(2rem, 5vw, 4rem);
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .hero-content {
            text-align: center;
        }

        .hero-content h1 {
            font-size: clamp(2.5rem, 6vw, 3.5rem);
            font-weight: 800;
            line-height: 1.1;
            color: white;
            margin-bottom: 1.5rem;
            letter-spacing: -0.02em;
        }

        .hero-content p {
            font-size: clamp(1.1rem, 2.5vw, 1.25rem);
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2rem;
            line-height: 1.6;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-cta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 3rem;
        }

        .btn {
            padding: clamp(0.75rem, 2vw, 1rem) clamp(1.5rem, 4vw, 2rem);
            border-radius: 50px;
            font-weight: 600;
            font-size: clamp(0.9rem, 2vw, 1rem);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            white-space: nowrap;
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
            margin-top: 2rem;
        }

        .floating-cards {
            position: relative;
            width: 100%;
            height: clamp(300px, 40vw, 400px);
            max-width: 600px;
        }

        .floating-card {
            position: absolute;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: clamp(1rem, 3vw, 1.5rem);
            color: white;
            animation: float 6s ease-in-out infinite;
            width: clamp(140px, 25vw, 200px);
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
            font-size: clamp(1.5rem, 4vw, 2rem);
            margin-bottom: 0.5rem;
            display: block;
        }

        .floating-card h4 {
            font-size: clamp(0.9rem, 2vw, 1.1rem);
            margin-bottom: 0.25rem;
        }

        .floating-card p {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        /* Container */
        .container {
            max-width: var(--container-max);
            margin: 0 auto;
            padding: 0 var(--container-padding);
        }

        /* Section Headers */
        .section-header {
            text-align: center;
            margin-bottom: clamp(3rem, 6vw, 4rem);
        }

        .section-header h2 {
            font-size: clamp(2rem, 5vw, 2.5rem);
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }

        .section-header p {
            font-size: clamp(1rem, 2.5vw, 1.125rem);
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        /* Features Section */
        .features {
            padding: clamp(4rem, 10vw, 6rem) 0;
            background: var(--surface);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
            gap: clamp(1.5rem, 4vw, 2rem);
        }

        .feature-card {
            background: var(--surface);
            border-radius: 20px;
            padding: clamp(1.5rem, 4vw, 2rem);
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
            width: clamp(60px, 15vw, 80px);
            height: clamp(60px, 15vw, 80px);
            background: var(--primary-gradient);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: clamp(1.5rem, 4vw, 2rem);
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
            font-size: clamp(1.1rem, 3vw, 1.25rem);
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .feature-card p {
            color: var(--text-secondary);
            line-height: 1.6;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        /* How It Works Section */
        .how-it-works {
            padding: clamp(4rem, 10vw, 6rem) 0;
            background: var(--surface-soft);
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr));
            gap: clamp(2rem, 5vw, 3rem);
            margin-top: clamp(3rem, 6vw, 4rem);
        }

        .step-card {
            text-align: center;
            position: relative;
        }

        .step-number {
            width: clamp(60px, 15vw, 80px);
            height: clamp(60px, 15vw, 80px);
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(1.5rem, 4vw, 2rem);
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

        .step-card h3 {
            font-size: clamp(1.1rem, 3vw, 1.25rem);
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .step-card p {
            color: var(--text-secondary);
            line-height: 1.6;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        /* Benefits Section */
        .benefits {
            padding: clamp(4rem, 10vw, 6rem) 0;
            background: var(--surface);
        }

        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr));
            gap: clamp(1.5rem, 4vw, 2rem);
            margin-top: clamp(3rem, 6vw, 4rem);
        }

        .benefit-item {
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            padding: clamp(1.5rem, 4vw, 2rem);
            background: var(--surface);
            border-radius: 15px;
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .benefit-item:hover {
            box-shadow: var(--shadow-medium);
        }

        .benefit-icon {
            width: clamp(50px, 12vw, 60px);
            height: clamp(50px, 12vw, 60px);
            background: var(--primary-gradient);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(1.2rem, 3vw, 1.5rem);
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

        .benefit-item h4 {
            font-size: clamp(1rem, 2.5vw, 1.1rem);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .benefit-item p {
            color: var(--text-secondary);
            line-height: 1.6;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        /* FAQ Section */
        .faq {
            padding: clamp(4rem, 10vw, 6rem) 0;
            background: var(--surface-soft);
        }

        .faq-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .faq-item {
            background: var(--surface);
            border-radius: 15px;
            margin-bottom: 1rem;
            border: 1px solid var(--border-light);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .faq-item:hover {
            box-shadow: var(--shadow-soft);
        }

        .faq-question {
            width: 100%;
            background: none;
            border: none;
            padding: clamp(1.25rem, 3vw, 1.5rem);
            text-align: left;
            font-size: clamp(1rem, 2.5vw, 1.1rem);
            font-weight: 600;
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .faq-question:hover {
            background: var(--surface-soft);
        }

        .faq-question i {
            transition: transform 0.3s ease;
            font-size: 1rem;
        }

        .faq-question.active i {
            transform: rotate(180deg);
        }

        .faq-answer {
            padding: 0 clamp(1.25rem, 3vw, 1.5rem);
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .faq-answer.active {
            max-height: 1000px;
            padding: 0 clamp(1.25rem, 3vw, 1.5rem) clamp(1.25rem, 3vw, 1.5rem);
        }

        .faq-answer p {
            color: var(--text-secondary);
            line-height: 1.6;
            font-size: clamp(0.9rem, 2vw, 1rem);
            margin-bottom: 1rem;
        }

        .faq-answer ul {
            color: var(--text-secondary);
            padding-left: 1.5rem;
            margin-bottom: 1rem;
        }

        .faq-answer li {
            margin-bottom: 0.5rem;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .faq-answer code {
            background: #f1f5f9;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 0.9em;
        }

        /* CTA Section */
        .cta-section {
            padding: clamp(4rem, 10vw, 6rem) 0;
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
            font-size: clamp(2rem, 5vw, 3rem);
            font-weight: 700;
            margin-bottom: 1.5rem;
            letter-spacing: -0.02em;
        }

        .cta-section p {
            font-size: clamp(1.1rem, 2.5vw, 1.25rem);
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
            padding: clamp(2rem, 5vw, 3rem) 0 1rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(250px, 100%), 1fr));
            gap: clamp(2rem, 5vw, 3rem);
            margin-bottom: 2rem;
        }

        .footer-section h3 {
            font-size: clamp(1.1rem, 2.5vw, 1.25rem);
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
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .footer-section a:hover {
            color: white;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 2rem;
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
            font-size: clamp(0.8rem, 2vw, 0.9rem);
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

        /* Mobile Responsive Improvements */
        @media (max-width: 768px) {
            .nav-links, .nav-auth {
                display: none;
            }
            
            .mobile-menu {
                display: block;
            }

            .hero-container {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .hero-cta {
                flex-direction: column;
                align-items: center;
            }

            .btn {
                width: 100%;
                max-width: 300px;
            }

            .floating-cards {
                height: 250px;
            }

            .floating-card {
                width: clamp(120px, 30vw, 160px);
            }

            .benefits-grid {
                grid-template-columns: 1fr;
            }

            .benefit-item {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
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
            .floating-card {
                padding: 0.75rem;
            }
            
            .floating-card h4 {
                font-size: 0.8rem;
            }
            
            .floating-card p {
                font-size: 0.7rem;
            }
        }

        @media (min-width: 769px) {
            .hero-container {
                grid-template-columns: 1fr 1fr;
                text-align: left;
            }

            .hero-visual {
                margin-top: 0;
            }

            .step-card:not(:last-child) .step-number::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 100%;
                width: 60px;
                height: 2px;
                background: linear-gradient(90deg, #3b82f6, transparent);
                transform: translateY(-50%);
            }
        }
    </style>
</head>

<body>
<!-- Navigation -->
<nav class="navbar" id="navbar">
    <div class="nav-container">
        <a href="#" class="logo">Shoppe Club</a>
        <ul class="nav-links">
            <li><a href="#home">Home</a></li>
            <li><a href="#features">Features</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#benefits">Benefits</a></li>
            <li><a href="#faq">FAQ</a></li>
        </ul>
        <div class="nav-auth">
            <a href="login.php" class="nav-login">Login</a>
            <a href="register.php" class="nav-register">Register</a>
        </div>
        <button class="mobile-menu" id="mobileMenu"><i class="fas fa-bars"></i></button>
    </div>
</nav>

<!-- Mobile Navigation -->
<div class="mobile-nav" id="mobileNav">
    <button class="mobile-close" id="mobileClose"><i class="fas fa-times"></i></button>
    <a href="#home">Home</a>
    <a href="#features">Features</a>
    <a href="#how-it-works">How It Works</a>
    <a href="#benefits">Benefits</a>
    <a href="#faq">FAQ</a>
    <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 2rem;">
        <a href="login.php" class="nav-login">Login</a>
        <a href="register.php" class="nav-register">Register</a>
    </div>
</div>

<!-- Back to Top Button -->
<button class="back-to-top" id="backToTop"><i class="fas fa-arrow-up"></i></button>

<!-- Hero Section -->
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

        <div class="hero-visual">
            <div class="floating-cards">
                <div class="floating-card">
                    <i class="fa-solid fa-bag-shopping"></i>
                    <h4>30% Token-Cashback</h4>
                    <p>On every cart</p>
                </div>
                <div class="floating-card">
                    <i class="fa-solid fa-sitemap"></i>
                    <h4>Binary Bonus</h4>
                    <p>10 to 30% per pair</p>
                </div>
                <div class="floating-card">
                    <i class="fa-solid fa-gift"></i>
                    <h4>Matched & Mentor</h4>
                    <p>5-level deep</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
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
                <p>Drop your unique .club handle on TikTok, IG, Discord. Friends shop → you earn 10% referral + binary pairs instantly.</p>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-icon"><i class="fa-solid fa-chart-pie"></i></div>
                <h3>Real-Time Dashboard</h3>
                <p>Track cashback, pairs, matched & mentor flows live. One-click swap earnings to USDT, USD, or store credit.</p>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
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
                <p>Buy groceries OR share your link. Either action drops PV into your binary buckets and fires 10% referral to your sponsor.</p>
            </div>
            <div class="step-card animate-on-scroll">
                <div class="step-number">3</div>
                <h3>Wake Up to Earnings</h3>
                <p>AI matches left/right legs at 00:00 UTC, pays 20% per pair, tops up cashback, and flushes the rest to tomorrow—automatically.</p>
            </div>
        </div>
    </div>
</section>

<!-- Benefits Section -->
<section class="benefits" id="benefits">
    <div class="container">
        <div class="section-header">
            <h2>Grow Your Wealth with Confidence</h2>
            <p>Built on battle-tested Shoppe Club mechanics, wrapped in a 2025-ready ecommerce shell.</p>
        </div>
        <div class="benefits-grid">
            <div class="benefit-item animate-on-scroll">
                <div class="benefit-icon"><i class="fas fa-wallet"></i></div>
                <div>
                    <h4>Simple eWallet System</h4>
                    <p>Easy checkout through your personal eWallet. Top up with USDT, spend with confidence, track everything in real-time.</p>
                </div>
            </div>
            <div class="benefit-item animate-on-scroll">
                <div class="benefit-icon"><i class="fas fa-rocket"></i></div>
                <div>
                    <h4>Instant Liquidity</h4>
                    <p>Cash out USDT to Apple Pay, Google Pay, or bank card in < 30 seconds—no minimums, no waiting.</p>
                </div>
            </div>
            <div class="benefit-item animate-on-scroll">
                <div class="benefit-icon"><i class="fas fa-chart-line"></i></div>
                <div>
                    <h4>Bear-Market Proof</h4>
                    <p>Cashback is dollar-denominated. If crypto crashes, flip payout to USD or store credit—your choice, every order.</p>
                </div>
            </div>            
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="faq" id="faq">
    <div class="container">
        <div class="section-header">
            <h2>Frequently Asked Questions</h2>
            <p>Everything you need to know about the Shoppe Club bonus system and affiliate marketing.</p>
        </div>
        <div class="faq-container">
            <div class="faq-item">
                <button class="faq-question">
                    <span>What is Shoppe Club and how does it work?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p>Shoppe Club is the world's first Buy-&-Build marketplace that turns shopping into earning. Every dollar you spend becomes Point Value (PV) that flows through a binary tree system, generating multiple income streams: referral bonuses, binary pairs, matched bonuses, and mentor bonuses.</p>
                    <p>Think of it as "shopping is the new mining" – instead of expensive mining equipment, your everyday purchases power your earnings.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>How do I start earning with affiliate marketing?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p>Getting started is simple and immediate:</p>
                    <ul>
                        <li>Register for free and choose your sponsor</li>
                        <li>Get your unique <code>yourname.club</code> affiliate link</li>
                        <li>Share your link on TikTok, Instagram, Discord, or anywhere online</li>
                        <li>Earn 10% instant referral bonus when someone buys through your link</li>
                        <li>Their purchases also add PV to your binary tree for additional earnings</li>
                    </ul>
                    <p>You can start sharing your link and earning commissions within minutes of joining – no waiting periods or minimum requirements.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>What is PV and how does the binary system work?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p><strong>PV (Point Value)</strong> equals the package price in dollars. Every $1 spent = 1 PV.</p>
                    <p><strong>Binary System:</strong> Imagine two buckets – LEFT and RIGHT. When someone in your network makes a purchase, their PV flows up to fill your buckets based on which side of your tree they're on.</p>
                    <p><strong>Daily Pairs:</strong> At 00:00 UTC daily, the system matches PV from both buckets. Each pair earns you $0.20 (20% of $1). Maximum 10 pairs per day. Matched PV is then flushed, and remaining PV carries over to tomorrow.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>What are the different bonus types?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p><strong>1. Referral Bonus (10%):</strong> Instant cash when someone you directly referred buys a package.</p>
                    <p><strong>2. Binary Bonus (20% per pair):</strong> Daily matching of your left/right PV buckets, up to 10 pairs per day.</p>
                    <p><strong>3. Matched Bonus (1-5%):</strong> Earn from binary pairs made by people in your downline network, based on your qualification levels.</p>
                    <p><strong>4. Mentor Bonus (1-3%):</strong> When you earn binary pairs, qualified people in your downline also receive a percentage.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>How do Matched and Mentor bonus qualifications work?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p><strong>Matched Bonus Requirements:</strong></p>
                    <ul>
                        <li>Level 1: 5% per pair | Need $100 PVT + $500 GVT</li>
                        <li>Level 2: 4% per pair | Need $200 PVT + $1,000 GVT</li>
                        <li>Level 3: 3% per pair | Need $300 PVT + $2,500 GVT</li>
                        <li>Level 4: 2% per pair | Need $500 PVT + $5,000 GVT</li>
                        <li>Level 5: 1% per pair | Need $1,000 PVT + $10,000 GVT</li>
                    </ul>
                    <p><strong>PVT</strong> = Your personal spending | <strong>GVT</strong> = Total group volume (you + all downlines)</p>
                    <p>If you don't meet requirements when someone earns a pair, that bonus is permanently lost for that specific occurrence.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>How do I withdraw my earnings?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p>Shoppe Club offers flexible withdrawal options:</p>
                    <ul>
                        <li><strong>USDT:</strong> Instant crypto withdrawal to your wallet</li>
                        <li><strong>USD:</strong> Direct bank transfer or card payment</li>
                        <li><strong>Store Credit:</strong> Use earnings for future purchases</li>
                    </ul>
                    <p>Withdrawals process in under 30 seconds with no minimum amounts or waiting periods. Your default wallet currency is in dollars for easy tracking.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>Is there a cost to join Shoppe Club?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p>Joining Shoppe Club is completely <strong>FREE</strong>. You can:</p>
                    <ul>
                        <li>Register at no cost</li>
                        <li>Get your affiliate link immediately</li>
                        <li>Start earning referral commissions right away</li>
                        <li>Access your dashboard and tracking tools</li>
                    </ul>
                    <p>You only spend money when you choose to purchase products for yourself, which then generates PV and activates the binary bonus system.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>What products can I buy and sell?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p>Shoppe Club offers a diverse marketplace including:</p>
                    <ul>
                        <li>Daily essentials and groceries</li>
                        <li>Skincare and beauty products</li>
                        <li>Digital products and NFTs</li>
                        <li>Phone top-ups and digital services</li>
                        <li>Eco-friendly and sustainable products</li>
                    </ul>
                    <p>All products are carefully vetted, and 1% of every order supports eco-friendly suppliers as part of our carbon-neutral commitment.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>How secure are my earnings and transactions?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p>Security and simplicity are our priorities:</p>
                    <ul>
                        <li><strong>eWallet Integration:</strong> Simple, secure digital wallet system</li>
                        <li><strong>USDT Top-ups:</strong> Easy funding with cryptocurrency</li>
                        <li><strong>Real-time Tracking:</strong> Monitor all transactions instantly</li>
                        <li><strong>Secure Checkout:</strong> Protected payment processing</li>
                    </ul>
                    <p>Your eWallet provides a streamlined shopping and earning experience.</p>
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-question">
                    <span>Can I track my earnings and network in real-time?</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="faq-answer">
                    <p>Yes! Your dashboard provides comprehensive real-time tracking:</p>
                    <ul>
                        <li>Live PV accumulation in both binary legs</li>
                        <li>Daily pair matching and earnings</li>
                        <li>All bonus types (referral, binary, matched, mentor)</li>
                        <li>Network growth and genealogy visualization</li>
                        <li>Wallet balance and transaction history</li>
                        <li>Qualification progress for higher bonus levels</li>
                    </ul>
                    <p>Everything updates instantly so you can monitor your success as it happens.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <h2>Ready to Turn Receipts into Revenue?</h2>
            <p>Join the wait-list, get 100 PV airdrop + lifetime 0% marketplace fees. Your first cashback hit lands in 60 seconds.</p>
            <a href="register.php" class="btn btn-primary"><i class="fas fa-star"></i>Join Shoppe Club Now</a>
        </div>
    </div>
</section>

<!-- Footer -->
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
                    <li><a href="#faq">FAQ</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h3>Support</h3>
                <ul>
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
            <p>&copy; 2025 Shoppe Club. All Rights Reserved. Built on Shoppe Club's proven engine, re-imagined for ecommerce.</p>
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

    // Mobile menu functionality
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileNav = document.getElementById('mobileNav');
    const mobileClose = document.getElementById('mobileClose');

    mobileMenu.addEventListener('click', function() {
        mobileNav.classList.add('active');
        document.body.style.overflow = 'hidden';
    });

    mobileClose.addEventListener('click', function() {
        mobileNav.classList.remove('active');
        document.body.style.overflow = '';
    });

    // Close mobile menu when clicking on links
    mobileNav.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', function() {
            mobileNav.classList.remove('active');
            document.body.style.overflow = '';
        });
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

    // FAQ Accordion functionality
    document.querySelectorAll('.faq-question').forEach(question => {
        question.addEventListener('click', function() {
            const answer = this.nextElementSibling;
            const isActive = this.classList.contains('active');

            // Close all other FAQ items
            document.querySelectorAll('.faq-question').forEach(q => {
                q.classList.remove('active');
                q.nextElementSibling.classList.remove('active');
            });

            // Toggle current FAQ item
            if (!isActive) {
                this.classList.add('active');
                answer.classList.add('active');
            }
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
                const offsetTop = target.offsetTop - 80; // Account for fixed navbar
                window.scrollTo({
                    top: offsetTop,
                    behavior: 'smooth'
                });
            }
        });
    });

    // Add loading animation
    window.addEventListener('load', function() {
        document.body.classList.add('loaded');
    });

    // Prevent body scroll when mobile menu is open
    function toggleBodyScroll(disable) {
        if (disable) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    // Enhanced mobile menu handling
    let isMenuOpen = false;
    
    mobileMenu.addEventListener('click', function() {
        isMenuOpen = !isMenuOpen;
        if (isMenuOpen) {
            mobileNav.classList.add('active');
            toggleBodyScroll(true);
        } else {
            mobileNav.classList.remove('active');
            toggleBodyScroll(false);
        }
    });

    // Close menu when clicking outside
    mobileNav.addEventListener('click', function(e) {
        if (e.target === mobileNav) {
            mobileNav.classList.remove('active');
            toggleBodyScroll(false);
            isMenuOpen = false;
        }
    });
</script>
</body>
</html>