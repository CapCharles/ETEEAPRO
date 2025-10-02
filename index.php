<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ETEEAP - Expanded Tertiary Education Equivalency and Accreditation Program</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-red: #dc2626;
            --dark-red: #991b1b;
            --light-red: #ef4444;
            --accent-red: #f87171;
            --bg-light: #fef2f2;
            --bg-white: #ffffff;
            --text-dark: #1e293b;
            --text-gray: #64748b;
            --border-color: #fecaca;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: var(--text-dark);
            background: var(--bg-light);
            overflow-x: hidden;
        }

        /* Enhanced Modern Navbar with Glass Effect */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 1.25rem 0;
            box-shadow: 0 2px 20px rgba(220, 38, 38, 0.1);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-bottom: 2px solid transparent;
        }

        .navbar.scrolled {
            padding: 0.75rem 0;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 4px 30px rgba(220, 38, 38, 0.15);
            border-bottom: 2px solid var(--accent-red);
        }

        .navbar::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-red), transparent);
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .navbar.scrolled::after {
            opacity: 1;
        }

        .navbar-brand {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-red) !important;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            position: relative;
        }

        .navbar-brand::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-red), var(--light-red));
            transition: width 0.4s ease;
        }

        .navbar-brand:hover::after {
            width: 100%;
        }

        .navbar-brand i {
            font-size: 2rem;
            animation: brandPulse 3s ease-in-out infinite;
        }

        @keyframes brandPulse {
            0%, 100% { transform: scale(1) rotate(0deg); }
            50% { transform: scale(1.1) rotate(5deg); }
        }

        .nav-link {
            color: var(--text-dark) !important;
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            margin: 0 0.25rem;
            border-radius: 8px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(220, 38, 38, 0.1);
            transform: translate(-50%, -50%);
            transition: width 0.4s ease, height 0.4s ease;
        }

        .nav-link:hover::before {
            width: 100%;
            height: 100%;
            border-radius: 8px;
        }

        .nav-link:hover {
            color: var(--primary-red) !important;
            transform: translateY(-2px);
        }

        .btn-nav-login {
            border: 2px solid var(--primary-red);
            color: var(--primary-red) !important;
            padding: 0.5rem 1.5rem !important;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            background: transparent;
            position: relative;
            overflow: hidden;
        }

        .btn-nav-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--primary-red);
            transition: left 0.4s ease;
            z-index: -1;
        }

        .btn-nav-login:hover::before {
            left: 0;
        }

        .btn-nav-login:hover {
            color: white !important;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 38, 38, 0.3);
        }

        .btn-nav-register {
            background: linear-gradient(135deg, var(--primary-red), var(--light-red));
            color: white !important;
            padding: 0.5rem 1.5rem !important;
            border-radius: 8px;
            font-weight: 600;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-nav-register::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .btn-nav-register:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-nav-register:hover {
            background: linear-gradient(135deg, var(--light-red), var(--primary-red));
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.4);
        }

        /* Enhanced Hero Section */
        .hero-section {
            padding: 160px 0 100px;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 50%, #fecaca 100%);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            width: 800px;
            height: 800px;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.2) 0%, transparent 70%);
            border-radius: 50%;
            top: -300px;
            right: -200px;
            animation: float 8s ease-in-out infinite;
            filter: blur(60px);
        }

        .hero-section::after {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            bottom: -200px;
            left: -150px;
            animation: float 10s ease-in-out infinite reverse;
            filter: blur(50px);
        }

        .particles {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: var(--primary-red);
            border-radius: 50%;
            opacity: 0.3;
            animation: particleFloat 15s infinite;
        }

        @keyframes particleFloat {
            0% {
                transform: translateY(100vh) translateX(0) scale(0);
                opacity: 0;
            }
            10% {
                opacity: 0.3;
            }
            90% {
                opacity: 0.3;
            }
            100% {
                transform: translateY(-100vh) translateX(100px) scale(1);
                opacity: 0;
            }
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }
            33% { transform: translate(30px, -30px) rotate(5deg) scale(1.1); }
            66% { transform: translate(-30px, 30px) rotate(-5deg) scale(0.9); }
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 900px;
            margin: 0 auto;
        }

        .hero-badge {
            display: inline-block;
            background: white;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary-red);
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
            animation: bounce 2s ease-in-out infinite;
            border: 2px solid var(--accent-red);
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-8px) scale(1.05); }
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            line-height: 1.2;
            animation: fadeInUp 0.8s ease;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.05);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero-subtitle {
            font-size: 1.25rem;
            color: var(--text-gray);
            margin-bottom: 2.5rem;
            line-height: 1.8;
            animation: fadeInUp 1s ease;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 3rem;
            animation: fadeInUp 1.2s ease;
        }

        .btn-hero-primary {
            background: linear-gradient(135deg, var(--primary-red), var(--light-red));
            color: white;
            padding: 1rem 2.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            border: none;
            transition: all 0.4s ease;
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
            position: relative;
            overflow: hidden;
        }

        .btn-hero-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s;
        }

        .btn-hero-primary::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .btn-hero-primary:hover::before {
            left: 100%;
        }

        .btn-hero-primary:hover::after {
            width: 300px;
            height: 300px;
        }

        .btn-hero-primary:hover {
            background: linear-gradient(135deg, var(--light-red), var(--primary-red));
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 12px 35px rgba(220, 38, 38, 0.5);
        }

        .btn-hero-secondary {
            background: white;
            color: var(--primary-red);
            padding: 1rem 2.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            border: 2px solid var(--primary-red);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-hero-secondary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--primary-red);
            transition: left 0.4s ease;
            z-index: -1;
        }

        .btn-hero-secondary:hover::before {
            left: 0;
        }

        .btn-hero-secondary:hover {
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
        }

        .hero-stats {
            display: flex;
            justify-content: center;
            gap: 3rem;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            animation: scaleIn 0.8s ease forwards;
            animation-delay: calc(var(--i) * 0.2s);
            opacity: 0;
            padding: 1.5rem 2rem;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 2px solid rgba(220, 38, 38, 0.2);
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 10px 30px rgba(220, 38, 38, 0.2);
            border-color: var(--primary-red);
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-red), var(--light-red));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
        }

        .stat-label {
            font-size: 0.95rem;
            color: var(--text-gray);
            font-weight: 500;
        }

        /* Enhanced Section Styling */
        .section {
            padding: 80px 0;
            position: relative;
        }

        .section-alt {
            background: white;
        }

        .section-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 4rem;
        }

        .section-badge {
            display: inline-block;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: var(--primary-red);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 1rem;
            border: 2px solid var(--accent-red);
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); }
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.05);
        }

        .section-description {
            font-size: 1.125rem;
            color: var(--text-gray);
        }

        /* Enhanced Feature Cards */
        .feature-card {
            background: white;
            padding: 2.5rem;
            border-radius: 20px;
            border: 2px solid var(--border-color);
            transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: all 0.6s ease;
            transform: rotate(0deg);
        }

        .feature-card:hover::before {
            opacity: 1;
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .feature-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-red), var(--light-red), var(--accent-red));
            transform: scaleX(0);
            transition: transform 0.5s ease;
        }

        .feature-card:hover::after {
            transform: scaleX(1);
        }

        .feature-card:hover {
            border-color: var(--primary-red);
            box-shadow: 0 15px 40px rgba(220, 38, 38, 0.25);
            transform: translateY(-12px) scale(1.03);
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-red), var(--light-red));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: white;
            margin-bottom: 1.5rem;
            position: relative;
            box-shadow: 0 10px 25px rgba(220, 38, 38, 0.4);
            animation: iconPulse 3s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.08); }
        }

        .feature-card:hover .feature-icon {
            animation: iconSpin 0.7s ease;
            box-shadow: 0 15px 35px rgba(220, 38, 38, 0.5);
        }

        @keyframes iconSpin {
            0% { transform: rotate(0deg) scale(1); }
            50% { transform: rotate(180deg) scale(1.15); }
            100% { transform: rotate(360deg) scale(1); }
        }

        .feature-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
        }

        .feature-description {
            color: var(--text-gray);
            line-height: 1.7;
        }

        /* Enhanced Download Section */
        .download-section {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            padding: 3rem;
            border-radius: 24px;
            border: 3px dashed var(--primary-red);
            margin-top: 3rem;
            position: relative;
            overflow: hidden;
        }

        .download-section::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            top: -150px;
            right: -150px;
            animation: pulse 5s ease-in-out infinite;
            filter: blur(40px);
        }

        .download-card {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            text-align: center;
            border: 2px solid var(--border-color);
            transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .download-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(220, 38, 38, 0.1), transparent);
            transition: left 0.6s;
        }

        .download-card:hover::before {
            left: 100%;
        }

        .download-card:hover {
            border-color: var(--primary-red);
            box-shadow: 0 12px 35px rgba(220, 38, 38, 0.3);
            transform: translateY(-10px) scale(1.05) rotate(2deg);
            background: linear-gradient(135deg, white, #fff5f5);
        }

        .download-icon {
            font-size: 2.5rem;
            color: var(--primary-red);
            margin-bottom: 1rem;
            transition: all 0.4s ease;
        }

        .download-card:hover .download-icon {
            transform: scale(1.3) rotate(15deg);
            color: var(--light-red);
        }

        /* CTA Section with Enhanced Effects */
        .cta-section {
            padding: 80px 0;
            background: linear-gradient(135deg, var(--primary-red), var(--dark-red));
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            top: -300px;
            right: -200px;
            animation: float 10s ease-in-out infinite;
        }

        .cta-section::after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            bottom: -250px;
            left: -150px;
            animation: float 12s ease-in-out infinite reverse;
        }

        .btn-cta {
            background: white;
            color: var(--primary-red);
            padding: 1rem 2.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            border: none;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-cta::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: var(--primary-red);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .btn-cta:hover::before {
            width: 400px;
            height: 400px;
        }

        .btn-cta:hover {
            color: white;
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 15px 40px rgba(255, 255, 255, 0.4);
        }

        .btn-cta span {
            position: relative;
            z-index: 1;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }

            .hero-subtitle {
                font-size: 1.1rem;
            }

            .section-title {
                font-size: 2rem;
            }

            .hero-stats {
                gap: 2rem;
            }

            .stat-number {
                font-size: 2rem;
            }
        }
    </style>
</head>

<body>
    <!-- Enhanced Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-graduation-cap"></i>
                ETEEAP
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#requirements">Requirements</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#programs">Programs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#process">Process</a>
                    </li>
                </ul>
                <div class="d-flex gap-2">
                    <a href="auth/login.php" class="nav-link btn-nav-login">Login</a>
                    <a href="auth/register.php" class="nav-link btn-nav-register">Get Started</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Enhanced Hero Section -->
    <section id="home" class="hero-section">
        <div class="particles">
            <div class="particle" style="left: 10%; animation-delay: 0s;"></div>
            <div class="particle" style="left: 20%; animation-delay: 2s;"></div>
            <div class="particle" style="left: 30%; animation-delay: 4s;"></div>
            <div class="particle" style="left: 40%; animation-delay: 6s;"></div>
            <div class="particle" style="left: 50%; animation-delay: 8s;"></div>
            <div class="particle" style="left: 60%; animation-delay: 10s;"></div>
            <div class="particle" style="left: 70%; animation-delay: 12s;"></div>
            <div class="particle" style="left: 80%; animation-delay: 14s;"></div>
            <div class="particle" style="left: 90%; animation-delay: 16s;"></div>
        </div>
        <div class="container">
            <div class="hero-content">
                <div class="hero-badge">
                    <i class="fas fa-certificate me-2"></i>Government Recognized Program
                </div>
                <h1 class="hero-title">Transform Your Experience Into Academic Excellence</h1>
                <p class="hero-subtitle">Fast-track your degree by converting years of professional expertise into recognized academic credits through the ETEEAP program.</p>
                <div class="hero-buttons">
                    <a href="auth/register.php" class="btn btn-hero-primary">
                        <i class="fas fa-rocket me-2"></i>Start Your Journey
                    </a>
                    <a href="#about" class="btn btn-hero-secondary">
                        <i class="fas fa-info-circle me-2"></i>Learn More</a>
                </div>
                <div class="hero-stats">
                    <div class="stat-item" style="--i: 0;">
                        <span class="stat-number">10,000+</span>
                        <span class="stat-label">Graduates</span>
                    </div>
                    <div class="stat-item" style="--i: 1;">
                        <span class="stat-number">95%</span>
                        <span class="stat-label">Success Rate</span>
                    </div>
                    <div class="stat-item" style="--i: 2;">
                        <span class="stat-number">1-2 Years</span>
                        <span class="stat-label">Completion Time</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="section section-alt">
        <div class="container">
            <div class="section-header">
                <span class="section-badge">About ETEEAP</span>
                <h2 class="section-title">What is ETEEAP?</h2>
                <p class="section-description">The Expanded Tertiary Education Equivalency and Accreditation Program recognizes your professional experience and converts it into academic credits.</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3 class="feature-title">Fast Track Your Degree</h3>
                        <p class="feature-description">Complete your degree in 1-2 years instead of the traditional 4-year program by leveraging your work experience.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-medal"></i>
                        </div>
                        <h3 class="feature-title">Official Recognition</h3>
                        <p class="feature-description">Established through Executive Order 330, ensuring your credentials are officially recognized and legitimate.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="feature-title">Career Advancement</h3>
                        <p class="feature-description">Boost your professional credentials and unlock new career opportunities with a recognized degree.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Requirements Section -->
    <section id="requirements" class="section">
        <div class="container">
            <div class="section-header">
                <span class="section-badge">Requirements</span>
                <h2 class="section-title">What You Need to Apply</h2>
                <p class="section-description">Make sure you meet these basic qualifications before starting your application</p>
            </div>
            
            <div class="row g-4 mb-5">
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <h4 class="feature-title">Basic Qualifications</h4>
                        <ul class="requirement-list" style="list-style: none; padding: 0;">
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Filipino citizen
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                At least 25 years old
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                High school diploma
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <h4 class="feature-title">Work Experience</h4>
                        <ul class="requirement-list" style="list-style: none; padding: 0;">
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                5+ years experience
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Related to field
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Documented proof
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h4 class="feature-title">Documents</h4>
                        <ul class="requirement-list" style="list-style: none; padding: 0;">
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Application form
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Application letter
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Detailed CV
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-certificate"></i>
                        </div>
                        <h4 class="feature-title">Certifications</h4>
                        <ul class="requirement-list" style="list-style: none; padding: 0;">
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Training certificates
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Professional licenses
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); padding-left: 1.5rem; position: relative;">
                                <span style="position: absolute; left: 0; color: var(--primary-red); font-weight: bold;">•</span>
                                Awards & achievements
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Download Section -->
            <div class="download-section">
                <h3 style="font-size: 1.35rem; font-weight: 600; color: var(--text-dark); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; position: relative; z-index: 1; justify-content: center;">
                    <i class="fas fa-download" style="animation: bounce 2s ease-in-out infinite;"></i>
                    Download Required Forms
                </h3>
                <p class="text-muted mb-4" style="text-align: center; position: relative; z-index: 1;">Get the official templates for your ETEEAP application</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; position: relative; z-index: 1;">
                    <div class="download-card" onclick="downloadForm('application')">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div style="font-weight: 600; color: var(--text-dark); margin-bottom: 0.5rem;">Application Form</div>
                        <div style="font-size: 0.875rem; color: var(--text-gray);">Official ETEEAP form</div>
                    </div>
                    <div class="download-card" onclick="downloadForm('letter')">
                        <div class="download-icon">
                            <i class="fas fa-file-word"></i>
                        </div>
                        <div style="font-weight: 600; color: var(--text-dark); margin-bottom: 0.5rem;">Application Letter</div>
                        <div style="font-size: 0.875rem; color: var(--text-gray);">Letter template</div>
                    </div>
                    <div class="download-card" onclick="downloadForm('cv')">
                        <div class="download-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div style="font-weight: 600; color: var(--text-dark); margin-bottom: 0.5rem;">CV Template</div>
                        <div style="font-size: 0.875rem; color: var(--text-gray);">Curriculum vitae format</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Programs Section -->
    <section id="programs" class="section section-alt">
        <div class="container">
            <div class="section-header">
                <span class="section-badge">Programs</span>
                <h2 class="section-title">Available Degree Programs</h2>
                <p class="section-description">Choose the program that matches your professional experience</p>
            </div>
            
            <div class="row g-4 justify-content-center">
                <div class="col-lg-5">
                    <div class="feature-card">
                        <div class="feature-icon" style="width: 80px; height: 80px; font-size: 2.25rem;">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <h3 style="font-size: 1.5rem; font-weight: 700; color: var(--text-dark); margin-bottom: 1rem;">Bachelor of Elementary Education (BEED)</h3>
                        <p style="color: var(--text-gray); margin-bottom: 1.5rem;">For educators with experience in elementary education and child development.</p>
                        <ul style="list-style: none; padding: 0;">
                            <li style="padding: 0.5rem 0; color: var(--text-gray); display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-check-circle" style="color: var(--primary-red); font-size: 1.1rem;"></i>Elementary Teaching Methods
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-check-circle" style="color: var(--primary-red); font-size: 1.1rem;"></i>Child Development
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-check-circle" style="color: var(--primary-red); font-size: 1.1rem;"></i>Curriculum Planning
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-check-circle" style="color: var(--primary-red); font-size: 1.1rem;"></i>Assessment & Evaluation
                            </li>
                        </ul>
                    </div>
                </div>
                
                <div class="col-lg-5">
                    <div class="feature-card">
                        <div class="feature-icon" style="width: 80px; height: 80px; font-size: 2.25rem;">
                            <i class="fas fa-university"></i>
                        </div>
                        <h3 style="font-size: 1.5rem; font-weight: 700; color: var(--text-dark); margin-bottom: 1rem;">Bachelor of Secondary Education (BSED)</h3>
                        <p style="color: var(--text-gray); margin-bottom: 1.5rem;">For professionals with secondary education teaching experience and subject expertise.</p>
                        <ul style="list-style: none; padding: 0;">
                            <li style="padding: 0.5rem 0; color: var(--text-gray); display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-check-circle" style="color: var(--primary-red); font-size: 1.1rem;"></i>Subject Specialization
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-check-circle" style="color: var(--primary-red); font-size: 1.1rem;"></i>Secondary Teaching Methods
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-check-circle" style="color: var(--primary-red); font-size: 1.1rem;"></i>Classroom Management
                            </li>
                            <li style="padding: 0.5rem 0; color: var(--text-gray); display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-check-circle" style="color: var(--primary-red); font-size: 1.1rem;"></i>Educational Psychology
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Process Section -->
    <section id="process" class="section">
        <div class="container">
            <div class="section-header">
                <span class="section-badge">How It Works</span>
                <h2 class="section-title">Simple 4-Step Process</h2>
                <p class="section-description">Get your professional experience recognized in just four easy steps</p>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; position: relative;">
                <div class="feature-card" style="text-align: center;">
                    <div style="width: 70px; height: 70px; background: linear-gradient(135deg, var(--primary-red), var(--light-red)); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; font-weight: 700; color: white; margin: 0 auto 1.5rem; box-shadow: 0 8px 20px rgba(220, 38, 38, 0.4); position: relative; transition: all 0.4s ease;">1</div>
                    <h4 style="font-size: 1.25rem; font-weight: 600; color: var(--text-dark); margin-bottom: 0.75rem;">Register</h4>
                    <p style="color: var(--text-gray); font-size: 0.95rem;">Create your account and complete the online application form with your personal information.</p>
                </div>
                
                <div class="feature-card" style="text-align: center;">
                    <div style="width: 70px; height: 70px; background: linear-gradient(135deg, var(--primary-red), var(--light-red)); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; font-weight: 700; color: white; margin: 0 auto 1.5rem; box-shadow: 0 8px 20px rgba(220, 38, 38, 0.4); position: relative; transition: all 0.4s ease;">2</div>
                    <h4 style="font-size: 1.25rem; font-weight: 600; color: var(--text-dark); margin-bottom: 0.75rem;">Submit Documents</h4>
                    <p style="color: var(--text-gray); font-size: 0.95rem;">Upload all required documents including credentials, employment records, and certifications.</p>
                </div>
                
                <div class="feature-card" style="text-align: center;">
                    <div style="width: 70px; height: 70px; background: linear-gradient(135deg, var(--primary-red), var(--light-red)); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; font-weight: 700; color: white; margin: 0 auto 1.5rem; box-shadow: 0 8px 20px rgba(220, 38, 38, 0.4); position: relative; transition: all 0.4s ease;">3</div>
                    <h4 style="font-size: 1.25rem; font-weight: 600; color: var(--text-dark); margin-bottom: 0.75rem;">Assessment</h4>
                    <p style="color: var(--text-gray); font-size: 0.95rem;">Expert panel evaluates your qualifications through tests, interviews, and portfolio review.</p>
                </div>
                
                <div class="feature-card" style="text-align: center;">
                    <div style="width: 70px; height: 70px; background: linear-gradient(135deg, var(--primary-red), var(--light-red)); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; font-weight: 700; color: white; margin: 0 auto 1.5rem; box-shadow: 0 8px 20px rgba(220, 38, 38, 0.4); position: relative; transition: all 0.4s ease;">4</div>
                    <h4 style="font-size: 1.25rem; font-weight: 600; color: var(--text-dark); margin-bottom: 0.75rem;">Get Results</h4>
                    <p style="color: var(--text-gray); font-size: 0.95rem;">Receive your assessment results and academic credits awarded based on your experience.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div style="position: relative; z-index: 2; max-width: 700px; margin: 0 auto;">
            <h2 style="font-size: 2.5rem; font-weight: 700; margin-bottom: 1rem;">Ready to Start Your Journey?</h2>
            <p style="font-size: 1.25rem; opacity: 0.9; margin-bottom: 2rem;">Transform your professional experience into academic credentials today and unlock new opportunities.</p>
            <a href="auth/register.php" class="btn btn-cta">
                <span><i class="fas fa-rocket me-2"></i>Start Your Application Now</span>
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer style="background: var(--text-dark); color: white; padding: 3rem 0 1.5rem;">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <div style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem;">
                        <i class="fas fa-graduation-cap me-2"></i>ETEEAP
                    </div>
                    <p style="color: #94a3b8; margin-bottom: 1.5rem;">Transforming professional experience into academic achievement through officially recognized assessment programs.</p>
                </div>
                <div class="col-md-3">
                    <h5 class="mb-3">Quick Links</h5>
                    <ul style="list-style: none; padding: 0;">
                        <li style="margin-bottom: 0.75rem;"><a href="#home" style="color: #94a3b8; text-decoration: none; transition: color 0.3s ease;">Home</a></li>
                        <li style="margin-bottom: 0.75rem;"><a href="#about" style="color: #94a3b8; text-decoration: none; transition: color 0.3s ease;">About</a></li>
                        <li style="margin-bottom: 0.75rem;"><a href="#requirements" style="color: #94a3b8; text-decoration: none; transition: color 0.3s ease;">Requirements</a></li>
                        <li style="margin-bottom: 0.75rem;"><a href="#programs" style="color: #94a3b8; text-decoration: none; transition: color 0.3s ease;">Programs</a></li>
                        <li style="margin-bottom: 0.75rem;"><a href="#process" style="color: #94a3b8; text-decoration: none; transition: color 0.3s ease;">Process</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h5 class="mb-3">Get Started</h5>
                    <ul style="list-style: none; padding: 0;">
                        <li style="margin-bottom: 0.75rem;"><a href="auth/register.php" style="color: #94a3b8; text-decoration: none; transition: color 0.3s ease;">Register</a></li>
                        <li style="margin-bottom: 0.75rem;"><a href="auth/login.php" style="color: #94a3b8; text-decoration: none; transition: color 0.3s ease;">Login</a></li>
                        <li style="margin-bottom: 0.75rem;"><a href="#requirements" style="color: #94a3b8; text-decoration: none; transition: color 0.3s ease;">Download Forms</a></li>
                    </ul>
                </div>
            </div>
            <div style="margin-top: 3rem; padding-top: 2rem; border-top: 1px solid rgba(255, 255, 255, 0.1); text-align: center; color: #94a3b8;">
                <p class="mb-0">&copy; 2025 ETEEAP Assessment Platform. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    const navHeight = document.querySelector('.navbar').offsetHeight;
                    const targetPosition = target.offsetTop - navHeight;
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Download form function
        function downloadForm(type) {
            let content = '';
            let filename = '';
            
            if (type === 'application') {
                content = generateApplicationForm();
                filename = 'ETEEAP-Application-Form.txt';
            } else if (type === 'letter') {
                content = generateApplicationLetter();
                filename = 'ETEEAP-Application-Letter-Template.txt';
            } else if (type === 'cv') {
                content = generateCVTemplate();
                filename = 'ETEEAP-CV-Template.txt';
            }
            
            const blob = new Blob([content], { type: 'text/plain' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        function generateApplicationForm() {
            return `ETEEAP APPLICATION FORM
Republic of the Philippines
Office of the President
COMMISSION ON HIGHER EDUCATION

Expanded Tertiary Education Equivalency and Accreditation Program (ETEEAP)

INSTRUCTION:
Please type or print clearly, provide complete and detailed information required. Do not leave blank unanswered; write "Not Applicable" as the case may be. All declarations that you make herewith are under oath. Discovery of any false claim in this application form will disqualify you from participating in the program. Use additional sheets if necessary.

I. PERSONAL INFORMATION

1. NAME (Last Name, First Name, Middle Name): _________________________
2. Address: ________________________________________________________________
   Zip Code: _______________
3. Telephone No(s).: _________________________
4. Birth Date: _________________________
5. Birthplace: _________________________
6. Civil Status: _________________________
7. Sex: ____________ Nationality: ____________________
8. Languages and Dialects Spoken: _________________________________
9. Degree Program or field being applied for:
   First Priority: ________________________________________
   Second Priority: ______________________________________
   Third Priority: _______________________________________

10. Statement of your goals/objectives/purposes in applying for the degree:
    ___________________________________________________________________
    ___________________________________________________________________`;
        }

        function generateApplicationLetter() {
            return `APPLICATION LETTER TEMPLATE

Date: ___________________

Dr./Mr./Ms. ____________________
(Designation of School Admin)
(Name of College or University -- HEI)
(School's address -- Line 1)
(School's address -- Line 2)

RE: Application for Enrollment in (Name of Degree) through the ETEEAP and Online Learning

Dear Dr./Mr./Ms. ____________:

I am writing you to express my intent to enroll in your (Name of Degree) through your ETEEAP. I am currently residing in (Your current location). I have attached the following relevant documents to support my qualifications:

1) Completed application form (Pages 1 to 4)
2) Detailed CV
3) COE-DFR (Name of Institution)`;
        }

        function generateCVTemplate() {
            return `COMPREHENSIVE CV TEMPLATE FOR ETEEAP

1. CURRENT JOB DETAILS

Detailed Duties, Functions and Responsibilities (DFR)
Name of company/institution: ________________________________
Date started -- Date ended: ________________________________

Main Duties:
- (Specify your primary duty)

Detailed Description of each function:
- Specific function 1
- Specific function 2
- Specific function 3`;
        }
    </script>
</body>
</html>
