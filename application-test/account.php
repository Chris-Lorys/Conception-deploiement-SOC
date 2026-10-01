<?php
session_start();

if (($_SESSION['role'] ?? '') !== 'user') {
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
    <title>Mon espace | apptest</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: Arial, sans-serif;
            color: #e8eef7;
            background: linear-gradient(135deg, #08111f, #101e32);
        }
        main {
            width: min(90%, 500px);
            padding: 36px;
            border: 1px solid #34465e;
            border-radius: 16px;
            background: #142237;
        }
        p { color: #aebdd0; }
        a { color: #59d5c3; }
    </style>
</head>
<body>
    <main>
        <h1>Bienvenue, <?= $username ?></h1>
        <p>Vous êtes connecté à votre espace utilisateur.</p>
        <a href="login.php?logout=1">Se déconnecter</a>
    </main>
</body>
</html>
