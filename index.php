<?php
require_once 'includes/auth.php';
require_once 'includes/csrf.php';

// Redirect to dashboard if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$username = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } elseif (verifyLogin($username, $password)) {
        header('Location: dashboard.php');
        exit();
    } else {
        $error = 'Invalid username or password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Green Valley Dairy Farm - Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@700;800&family=Inter:wght@400;500;600&family=Fraunces:ital,wght@0,500;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" type="image/x-icon" href="<?php echo BASE_URL; ?>assets/images/favicon.ico">
    <style>
        .login-shell {
            min-height: 100vh;
            display: flex;
            background: var(--brand-cream);
        }

        /* ---------- Brand panel: dawn gradient + horizon signature ---------- */

        .login-brand {
            flex: 1;
            position: relative;
            overflow: hidden;
            color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3rem;
            background: linear-gradient(200deg, var(--heading) 0%, var(--primary-dark) 55%, var(--brand-gold) 130%);
            background-size: 180% 180%;
            background-position: 20% 30%;
        }

        .login-brand-copy {
            position: relative;
            z-index: 2;
            max-width: 26rem;
        }

        .login-eyebrow {
            font-family: var(--font-accent);
            font-style: italic;
            font-size: 1rem;
            color: var(--brand-gold-soft);
            margin-bottom: 0.5rem;
        }

        .login-brand h1 {
            color: #fff;
            font-size: 2.35rem;
            letter-spacing: -0.01em;
        }

        .login-desc {
            color: #d1fae5;
            margin-top: 0.85rem;
            line-height: 1.55;
        }

        .login-horizon {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 42%;
            z-index: 1;
        }

        .horizon-hill-back {
            fill: rgba(255, 255, 255, 0.05);
        }

        .horizon-hill-front {
            fill: rgba(15, 35, 24, 0.45);
        }

        .horizon-sun {
            fill: var(--brand-gold-soft);
            opacity: 0.55;
            filter: blur(1px);
        }

        .horizon-herd ellipse,
        .horizon-herd rect {
            fill: rgba(250, 246, 236, 0.4);
        }

        /* ---------- Form panel ---------- */

        .login-form-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: #fff;
        }

        .login-form-card {
            width: 100%;
            max-width: 22rem;
        }

        .login-form-card h2 {
            font-size: 1.4rem;
            margin-bottom: 0.35rem;
        }

        .login-form-card p.subtitle {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .login-form-card .dfms-field input:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
            border-color: var(--primary);
        }

        .login-form-card .dfms-btn-primary {
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .login-form-card .dfms-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(21, 128, 61, 0.28);
        }

        /* ---------- Motion: staged entrance + ambient dawn drift ---------- */

        @media (prefers-reduced-motion: no-preference) {
            .login-brand {
                animation: dawn-drift 26s ease-in-out infinite alternate;
            }

            .horizon-sun {
                animation: sun-pulse 6s ease-in-out infinite;
            }

            .login-reveal {
                opacity: 0;
                animation: reveal-up 0.5s ease forwards;
            }

            .login-brand-copy .login-eyebrow { animation-delay: 0.05s; }
            .login-brand-copy h1 { animation-delay: 0.15s; }
            .login-brand-copy p { animation-delay: 0.25s; }
            .login-form-card h2 { animation-delay: 0.1s; }
            .login-form-card p.subtitle { animation-delay: 0.18s; }
            .login-form-card form { animation-delay: 0.26s; }
        }

        @keyframes dawn-drift {
            from { background-position: 15% 25%; }
            to { background-position: 55% 60%; }
        }

        @keyframes sun-pulse {
            0%, 100% { opacity: 0.45; }
            50% { opacity: 0.7; }
        }

        @keyframes reveal-up {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dfms-alert-error {
            animation: alert-in 0.25s ease;
        }

        @keyframes alert-in {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 900px) {
            .login-brand {
                display: none;
            }
        }
    </style>
</head>

<body class="dfms">
    <div class="login-shell">
        <div class="login-brand">
            <svg class="login-horizon" viewBox="0 0 800 220" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
                <path class="horizon-hill-back" d="M0,140 C120,110 220,150 340,120 C460,90 560,130 680,105 C740,95 780,110 800,115 L800,220 L0,220 Z" />
                <circle class="horizon-sun" cx="640" cy="70" r="34" />
                <path class="horizon-hill-front" d="M0,175 C100,150 200,180 300,160 C420,135 520,170 620,150 C700,135 760,155 800,150 L800,220 L0,220 Z" />
                <g class="horizon-herd">
                    <g transform="translate(160,150)">
                        <ellipse cx="0" cy="0" rx="13" ry="8" />
                        <rect x="-9" y="5" width="3" height="9" />
                        <rect x="4" y="5" width="3" height="9" />
                        <ellipse cx="-14" cy="-4" rx="6" ry="5" />
                    </g>
                    <g transform="translate(210,158) scale(0.85)">
                        <ellipse cx="0" cy="0" rx="13" ry="8" />
                        <rect x="-9" y="5" width="3" height="9" />
                        <rect x="4" y="5" width="3" height="9" />
                        <ellipse cx="-14" cy="-4" rx="6" ry="5" />
                    </g>
                    <g transform="translate(255,152) scale(0.7)">
                        <ellipse cx="0" cy="0" rx="13" ry="8" />
                        <rect x="-9" y="5" width="3" height="9" />
                        <rect x="4" y="5" width="3" height="9" />
                        <ellipse cx="-14" cy="-4" rx="6" ry="5" />
                    </g>
                </g>
            </svg>

            <div class="login-brand-copy">
                <p class="login-eyebrow login-reveal">Est. for the morning shift</p>
                <h1 class="login-reveal">Green Valley Dairy Farm</h1>
                <p class="login-desc login-reveal">Every cow, every liter, every vaccination — tracked from first light in the shed to the ledger on your desk.</p>
            </div>
        </div>

        <div class="login-form-panel">
            <div class="login-form-card">
                <h2 class="login-reveal">Welcome back</h2>
                <p class="subtitle login-reveal">Sign in to manage today's herd.</p>

                <?php if ($error): ?>
                    <div class="dfms-alert dfms-alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" class="login-reveal">
                    <?php csrf_field(); ?>
                    <div class="dfms-field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" required autofocus
                            value="<?php echo htmlspecialchars($username); ?>">
                    </div>
                    <div class="dfms-field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" required>
                    </div>
                    <button type="submit" class="dfms-btn dfms-btn-primary" style="width:100%;">Sign in</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>
