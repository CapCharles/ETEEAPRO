<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ETEEAPROS - Expanded Tertiary Education Equivalency and Accreditation Program</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #2563eb;
            --dark-blue: #1e40af;
            --light-blue: #3b82f6;
            --accent-blue: #60a5fa;
            --bg-light: #f8fafc;
            --bg-white: #ffffff;
            --text-dark: #1e293b;
            --text-gray: #64748b;
            --border-color: #e2e8f0;
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
        }

        /* Modern Navbar */
        .navbar {
            background: var(--bg-white);
            padding: 1.25rem 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .navbar.scrolled {
            padding: 0.75rem 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .navbar-brand {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-blue) !important;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .navbar-brand i {
            font-size: 2rem;
        }

        .nav-link {
            color: var(--text-dark) !important;
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            margin: 0 0.25rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: var(--bg-light);
            color: var(--primary-blue) !important;
        }

        .btn-nav-login {
            border: 2px solid var(--primary-blue);
            color: var(--primary-blue) !important;
            padding: 0.5rem 1.5rem !important;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            background: transparent;
        }

        .btn-nav-login:hover {
            background: var(--primary-blue);
            color: white !important;
        }

        .btn-nav-register {
            background: var(--primary-blue);
            color: white !important;
            padding: 0.5rem 1.5rem !important;
            border-radius: 8px;
            font-weight: 600;
            border: 2px solid var(--primary-blue);
            transition: all 0.3s ease;
        }

        .btn-nav-register:hover {
            background: var(--dark-blue);
            border-color: var(--dark-blue);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        /* Hero Section - Centered Design */
        .hero-section {
            padding: 140px 0 80px;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 50%, #e0f2fe 100%);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            top: -200px;
            right: -100px;
            animation: float 6s ease-in-out infinite;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.12) 0%, transparent 70%);
            border-radius: 50%;
            bottom: -150px;
            left: -100px;
            animation: float 8s ease-in-out infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(20px, 20px) rotate(5deg); }
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
            color: var(--primary-blue);
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            line-height: 1.2;
            animation: fadeInUp 0.8s ease;
        }

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

        .hero-subtitle {
            font-size: 1.25rem;
            color: var(--text-gray);
            margin-bottom: 2.5rem;
            line-height: 1.7;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 3rem;
        }

        .btn-hero-primary {
            background: linear-gradient(135deg, var(--primary-blue), var(--light-blue));
            color: white;
            padding: 1rem 2.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            border: none;
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
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
            transition: left 0.5s;
        }

        .btn-hero-primary:hover::before {
            left: 100%;
        }

        .btn-hero-primary:hover {
            background: linear-gradient(135deg, var(--light-blue), var(--primary-blue));
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.5);
        }

        .btn-hero-secondary {
            background: white;
            color: var(--primary-blue);
            padding: 1rem 2.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            border: 2px solid var(--primary-blue);
            transition: all 0.3s ease;
        }

        .btn-hero-secondary:hover {
            background: var(--bg-light);
            transform: translateY(-2px);
        }

        .hero-stats {
            display: flex;
            justify-content: center;
            gap: 3rem;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            animation: scaleIn 0.6s ease forwards;
            animation-delay: calc(var(--i) * 0.2s);
            opacity: 0;
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-blue), var(--light-blue));
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

        /* Section Styling */
        .section {
            padding: 80px 0;
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
            background: #eff6ff;
            color: var(--primary-blue);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }

        .section-description {
            font-size: 1.125rem;
            color: var(--text-gray);
        }

        /* Feature Cards */
        .feature-card {
            background: white;
            padding: 2.5rem;
            border-radius: 20px;
            border: 2px solid var(--border-color);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(59, 130, 246, 0.05));
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-card:hover {
            border-color: var(--primary-blue);
            box-shadow: 0 12px 35px rgba(37, 99, 235, 0.2);
            transform: translateY(-10px) scale(1.02);
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-blue), var(--light-blue));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: white;
            margin-bottom: 1.5rem;
            position: relative;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3);
            animation: iconPulse 2s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .feature-card:hover .feature-icon {
            animation: iconSpin 0.6s ease;
        }

        @keyframes iconSpin {
            0% { transform: rotate(0deg) scale(1); }
            50% { transform: rotate(180deg) scale(1.1); }
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

        /* Requirements Section */
        .requirement-card {
            background: white;
            padding: 2rem;
            border-radius: 18px;
            border: 2px solid var(--border-color);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .requirement-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-blue), var(--light-blue));
            transform: scaleX(0);
            transition: transform 0.4s ease;
        }

        .requirement-card:hover::after {
            transform: scaleX(1);
        }

        .requirement-card:hover {
            border-color: var(--primary-blue);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.15);
            transform: translateY(-5px);
        }

        .requirement-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-blue);
            font-size: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .requirement-card:hover .requirement-icon {
            background: linear-gradient(135deg, var(--primary-blue), var(--light-blue));
            color: white;
            transform: rotate(360deg);
        }

        .requirement-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
        }

        .requirement-list {
            list-style: none;
            padding: 0;
        }

        .requirement-list li {
            padding: 0.5rem 0;
            color: var(--text-gray);
            padding-left: 1.5rem;
            position: relative;
        }

        .requirement-list li::before {
            content: '•';
            position: absolute;
            left: 0;
            color: var(--primary-blue);
            font-weight: bold;
        }

        /* Process Steps */
        .process-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            position: relative;
        }

        .step-card {
            background: white;
            padding: 2rem;
            border-radius: 20px;
            text-align: center;
            border: 2px solid var(--border-color);
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            position: relative;
            overflow: hidden;
        }

        .step-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .step-card:hover::before {
            opacity: 1;
            animation: rotate 10s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .step-card:hover {
            border-color: var(--primary-blue);
            box-shadow: 0 12px 30px rgba(37, 99, 235, 0.2);
            transform: translateY(-10px) scale(1.03);
        }

        .step-number {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-blue), var(--light-blue));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            font-weight: 700;
            color: white;
            margin: 0 auto 1.5rem;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
            position: relative;
            transition: all 0.4s ease;
        }

        .step-card:hover .step-number {
            transform: rotateY(360deg) scale(1.1);
        }

        .step-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
        }

        .step-description {
            color: var(--text-gray);
            font-size: 0.95rem;
        }

        /* Program Cards */
        .program-card {
            background: white;
            padding: 2.5rem;
            border-radius: 20px;
            border: 2px solid var(--border-color);
            transition: all 0.4s ease;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .program-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(37, 99, 235, 0.1), transparent);
            transition: left 0.6s;
        }

        .program-card:hover::before {
            left: 100%;
        }

        .program-card:hover {
            border-color: var(--primary-blue);
            box-shadow: 0 15px 40px rgba(37, 99, 235, 0.2);
            transform: translateY(-10px) scale(1.02);
        }

        .program-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.25rem;
            color: var(--primary-blue);
            margin-bottom: 1.5rem;
            transition: all 0.4s ease;
        }

        .program-card:hover .program-icon {
            background: linear-gradient(135deg, var(--primary-blue), var(--light-blue));
            color: white;
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3);
        }

        .program-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }

        .program-description {
            color: var(--text-gray);
            margin-bottom: 1.5rem;
        }

        .program-features {
            list-style: none;
            padding: 0;
        }

        .program-features li {
            padding: 0.5rem 0;
            color: var(--text-gray);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .program-features li i {
            color: var(--primary-blue);
            font-size: 1.1rem;
        }

        /* CTA Section */
        .cta-section {
            padding: 80px 0;
            background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue));
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
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            top: -300px;
            right: -200px;
        }

        .cta-content {
            position: relative;
            z-index: 2;
            max-width: 700px;
            margin: 0 auto;
        }

        .cta-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .cta-description {
            font-size: 1.25rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }

        .btn-cta {
            background: white;
            color: var(--primary-blue);
            padding: 1rem 2.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            border: none;
            transition: all 0.3s ease;
        }

        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(255, 255, 255, 0.3);
        }

        /* Footer */
        footer {
            background: var(--text-dark);
            color: white;
            padding: 3rem 0 1.5rem;
        }

        .footer-brand {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .footer-description {
            color: #94a3b8;
            margin-bottom: 1.5rem;
        }

        .footer-links {
            list-style: none;
            padding: 0;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: #94a3b8;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: white;
        }

        .footer-bottom {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #94a3b8;
        }

        /* Download Links */
        .download-section {
            background: linear-gradient(135deg, #eff6ff, #e0f2fe);
            padding: 2.5rem;
            border-radius: 20px;
            border: 3px dashed var(--primary-blue);
            margin-top: 3rem;
            position: relative;
            overflow: hidden;
        }

        .download-section::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            top: -100px;
            right: -100px;
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }

        .download-title {
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .download-title i {
            animation: bounce 2s ease-in-out infinite;
        }

        .download-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .download-card {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            text-align: center;
            border: 2px solid var(--border-color);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .download-card::after {
            content: '⬇';
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 1.5rem;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s ease;
        }

        .download-card:hover::after {
            opacity: 1;
            transform: translateY(0);
        }

        .download-card:hover {
            border-color: var(--primary-blue);
            box-shadow: 0 10px 30px rgba(37, 99, 235, 0.2);
            transform: translateY(-8px) scale(1.05);
            background: linear-gradient(135deg, white, #f8fbff);
        }

        .download-icon {
            font-size: 2.5rem;
            color: var(--primary-blue);
            margin-bottom: 1rem;
            transition: all 0.4s ease;
        }

        .download-card:hover .download-icon {
            transform: scale(1.2) rotate(10deg);
            color: var(--light-blue);
        }

        .download-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .download-desc {
            font-size: 0.875rem;
            color: var(--text-gray);
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

            .cta-title {
                font-size: 2rem;
            }
        }
    </style>
</head>

<body>
    <!-- Modern Navbar -->
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

    <!-- Hero Section -->
    <section id="home" class="hero-section">
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
                        <i class="fas fa-info-circle me-2"></i>Learn More
                    </a>
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
                    <div class="requirement-card">
                        <div class="requirement-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <h4 class="requirement-title">Basic Qualifications</h4>
                        <ul class="requirement-list">
                            <li>Filipino citizen</li>
                            <li>At least 25 years old</li>
                            <li>High school diploma</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="requirement-card">
                        <div class="requirement-icon">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <h4 class="requirement-title">Work Experience</h4>
                        <ul class="requirement-list">
                            <li>5+ years experience</li>
                            <li>Related to field</li>
                            <li>Documented proof</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="requirement-card">
                        <div class="requirement-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h4 class="requirement-title">Documents</h4>
                        <ul class="requirement-list">
                            <li>Application form</li>
                            <li>Application letter</li>
                            <li>Detailed CV</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="requirement-card">
                        <div class="requirement-icon">
                            <i class="fas fa-certificate"></i>
                        </div>
                        <h4 class="requirement-title">Certifications</h4>
                        <ul class="requirement-list">
                            <li>Training certificates</li>
                            <li>Professional licenses</li>
                            <li>Awards & achievements</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Download Section -->
            <div class="download-section">
                <h3 class="download-title">
                    <i class="fas fa-download"></i>
                    Download Required Forms
                </h3>
                <p class="text-muted mb-4">Get the official templates for your ETEEAP application</p>
                <div class="download-grid">
                    <div class="download-card" onclick="downloadForm('application')">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="download-name">Application Form</div>
                        <div class="download-desc">Official ETEEAP form</div>
                    </div>
                    <div class="download-card" onclick="downloadForm('letter')">
                        <div class="download-icon">
                            <i class="fas fa-file-word"></i>
                        </div>
                        <div class="download-name">Application Letter</div>
                        <div class="download-desc">Letter template</div>
                    </div>
                    <div class="download-card" onclick="downloadForm('cv')">
                        <div class="download-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="download-name">CV Template</div>
                        <div class="download-desc">Curriculum vitae format</div>
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
                    <div class="program-card">
                        <div class="program-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <h3 class="program-title">Bachelor of Elementary Education (BEED)</h3><p class="program-description">For educators with experience in elementary education and child development.</p>
                        <ul class="program-features">
                            <li><i class="fas fa-check-circle"></i>Elementary Teaching Methods</li>
                            <li><i class="fas fa-check-circle"></i>Child Development</li>
                            <li><i class="fas fa-check-circle"></i>Curriculum Planning</li>
                            <li><i class="fas fa-check-circle"></i>Assessment & Evaluation</li>
                        </ul>
                    </div>
                </div>
                
                <div class="col-lg-5">
                    <div class="program-card">
                        <div class="program-icon">
                            <i class="fas fa-university"></i>
                        </div>
                        <h3 class="program-title">Bachelor of Secondary Education (BSED)</h3>
                        <p class="program-description">For professionals with secondary education teaching experience and subject expertise.</p>
                        <ul class="program-features">
                            <li><i class="fas fa-check-circle"></i>Subject Specialization</li>
                            <li><i class="fas fa-check-circle"></i>Secondary Teaching Methods</li>
                            <li><i class="fas fa-check-circle"></i>Classroom Management</li>
                            <li><i class="fas fa-check-circle"></i>Educational Psychology</li>
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
            
            <div class="process-steps">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <h4 class="step-title">Register</h4>
                    <p class="step-description">Create your account and complete the online application form with your personal information.</p>
                </div>
                
                <div class="step-card">
                    <div class="step-number">2</div>
                    <h4 class="step-title">Submit Documents</h4>
                    <p class="step-description">Upload all required documents including credentials, employment records, and certifications.</p>
                </div>
                
                <div class="step-card">
                    <div class="step-number">3</div>
                    <h4 class="step-title">Assessment</h4>
                    <p class="step-description">Expert panel evaluates your qualifications through tests, interviews, and portfolio review.</p>
                </div>
                
                <div class="step-card">
                    <div class="step-number">4</div>
                    <h4 class="step-title">Get Results</h4>
                    <p class="step-description">Receive your assessment results and academic credits awarded based on your experience.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="cta-content">
            <h2 class="cta-title">Ready to Start Your Journey?</h2>
            <p class="cta-description">Transform your professional experience into academic credentials today and unlock new opportunities.</p>
            <a href="auth/register.php" class="btn btn-cta">
                <i class="fas fa-rocket me-2"></i>Start Your Application Now
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <div class="footer-brand">
                        <i class="fas fa-graduation-cap me-2"></i>ETEEAP
                    </div>
                    <p class="footer-description">Transforming professional experience into academic achievement through officially recognized assessment programs.</p>
                </div>
                <div class="col-md-3">
                    <h5 class="mb-3">Quick Links</h5>
                    <ul class="footer-links">
                        <li><a href="#home">Home</a></li>
                        <li><a href="#about">About</a></li>
                        <li><a href="#requirements">Requirements</a></li>
                        <li><a href="#programs">Programs</a></li>
                        <li><a href="#process">Process</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h5 class="mb-3">Get Started</h5>
                    <ul class="footer-links">
                        <li><a href="auth/register.php">Register</a></li>
                        <li><a href="auth/login.php">Login</a></li>
                        <li><a href="#requirements">Download Forms</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
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
    ___________________________________________________________________

11. Indicate how much time you plan to devote for personal learning activities:
    ___________________________________________________________________
    ___________________________________________________________________

12. For overseas applicants, describe how you plan to obtain accreditation/equivalency:
    ___________________________________________________________________
    ___________________________________________________________________

13. How soon do you need to complete equivalency/accreditation?
    _____ less than one (1) year  _____ 1 year
    _____ 2 years  _____ 3 years

II. EDUCATION:

1. Formal Education
Course/Degree Program | Name of School/Address | Inclusive Dates of Attendance
_________________________________________________________________
_________________________________________________________________

2. Non-Formal Education
Title of Training Program | Title of Certificate Obtained | Inclusive Dates of Attendance
_________________________________________________________________
_________________________________________________________________

3. Other Certification Examinations
Title of Certification Examination | Name/Address of Certifying Agency | Date Certified | Rating
_________________________________________________________________
_________________________________________________________________

III. PAID WORK AND OTHER EXPERIENCES

1. Post/Designation: _________________________________________
2. Inclusive Dates of Employment: From: _____________ to: _____________
3. Name and Address of Company: ________________________________
4. Terms/Status of Employment: _________________________________
5. Name and Designation of Immediate Supervisor: ___________________
6. Reason(s) for moving on to the next job: ________________________
7. Describe actual functions and responsibilities in position occupied:
   ________________________________________________________________
   ________________________________________________________________

IV. HONORS, AWARDS, AND CITATIONS RECEIVED

1. Academic Award
Award Conferred | Name and Address of Conferring Organization | Date Awarded
________________________________________________________________

2. Community and Civic Organization Award/Citation
Award Conferred | Name and Address of Conferring Organization | Date Awarded
________________________________________________________________

3. Work Related Award/Citation
Award Conferred | Name and Address of Conferring Organization | Date Awarded
________________________________________________________________

V. CREATIVE WORKS AND SPECIAL ACCOMPLISHMENTS

1. Description: ______________________________________________
2. Date Accomplished: _______________________________________
3. Name and Address of Publishing Agency: _____________________

VI. LIFELONG LEARNING EXPERIENCE

1. Hobbies/Leisure Activities: ________________________________
2. Special Skills: __________________________________________
3. Work-Related Activities: __________________________________
4. Volunteer Activities: ____________________________________
5. Travels: _______________________________________________

VII. Essay on how your attaining a degree contribute to your personal development, your community, your workplace, society, and country:
________________________________________________________________
________________________________________________________________
________________________________________________________________

I declare under oath that, the foregoing claims and information I have disclosed are true and correct.

Signed: ________________________________
Printed Name and Signature of Applicant

Community Tax Certificate: _____________________
Issued on: ______________ at: __________________`;
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
3) COE-DFR (Name of Institution) - (Service Record-Employment Certificate-Detailed Job Description)-Current Job
4) COE-DFR- (Name of Institution) - (Service Record-Employment Certificate-Detailed Job Description)-Former Job
5) Passport (Photo Page)
6) Bachelor's Degree Certificate or Diploma (Name of Institution)
7) Bachelor's Degree transcript with (Name of Institution)
8) Birth Certificate (NSO)
9) Professional certifications and licenses
10) Training certificates and workshops
11) Awards and recognitions
12) Portfolio of work achievements

In addition to the above, I also have the following projects and publications I have created with the pertinent documents attached with this application.

1) _________________________ (Brief description)
2) _________________________ (Brief description)
3) _________________________ (Brief description)

Thank you and I hope and pray that my application be given consideration and my request to enroll in the above-mentioned degree through the ETEEAP via online or modular distance learning will be granted.

Sincerely yours,

Your name and signature
Designation or title`;
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
- Specific function 3

Other functions:
- Additional responsibility 1
- Additional responsibility 2

General Summary of Skills:
- Skill 1
- Skill 2
- Skill 3

Other Relevant Information (Optional):
Any information that may add to your expertise in the field you mentioned above. Be specific as this can add to the overall score.

2. EDUCATION

Date started -- Date completed -- (Name of Institution)
(Address or location of institution)
- Name of educational program or degree, if applicable
- Main focus -- Give short description of the program. If not completed, mention it.

(Repeat for all educational experiences including elementary and secondary education)

3. TRAININGS and PROFESSIONAL DEVELOPMENT SEMINARS

Date covered -- Title of training or seminar, and location, and sponsor or organizer
- Brief description as what was learned and skills acquired in the training.
- Describe your role and other participations and contributions you made.

(Repeat for each training/seminar)

4. AWARDS

Date - Award Title | Institution | Brief description of award

5. COMPETENCIES & INTERESTS

English Ability:
- IELTS Band Score -- Score (Date), if applicable and available
- TOEIC Score - Score (Date), if applicable and available
- Other English proficiency skill descriptions or test/training you have taken up.

Computer Skills:
(List all relevant computer skills and software proficiency)

Professional Skills:
(List skills relevant to your field)

Other Skills and Interests:
(List hobbies, volunteer work, and other relevant activities)

I certify that the above information are true and correct to the best of my ability.

Your name with your signature
Date prepared and signed
Your location`;
        }
    </script>
</body>
</html>