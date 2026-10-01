<?php
session_start();

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration | apptest</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            color: #e8eef7;
            background: linear-gradient(135deg, #08111f, #101e32);
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 22px max(24px, calc((100vw - 900px) / 2));
            border-bottom: 1px solid #34465e;
            background: #142237;
        }
        .brand {
            color: #59d5c3;
            font-weight: bold;
            letter-spacing: 3px;
        }
        a {
            color: #59d5c3;
            text-decoration: none;
        }
        main {
            max-width: 900px;
            margin: 70px auto;
            padding: 0 24px;
        }
        h1 { font-size: 34px; }
        p { color: #aebdd0; line-height: 1.6; }
        .card {
            margin-top: 32px;
            padding: 28px;
            border: 1px solid #34465e;
            border-radius: 16px;
            background: #142237;
        }
        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 7px;
            color: #b9f5d0;
            background: #163c32;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <header>
        <span class="brand">APPTEST</span>
        <a href="login.php?logout=1">Se déconnecter</a>
    </header>
    <main>
        <span class="badge">Espace administrateur</span>
        <h1>Bienvenue, <?= $username ?></h1>
        <div class="card">
            <h2>Tableau de bord</h2>
            <p>Vous avez accédé à la page réservée au compte administrateur.</p>
        </div>
    </main>
</body>
</html>
