<?php
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        $db = new SQLite3('/var/www/html/apptest/lab.db', SQLITE3_OPEN_READONLY);

        // Requête volontairement vulnérable pour le laboratoire.
        $query = "SELECT username, role FROM login_accounts WHERE username = '$username' AND password = '$password' LIMIT 1";

        $result = $db->query($query);
        $user = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

        if ($user) {
            session_regenerate_id(true);
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header($user['role'] === 'admin' ? 'Location: admin.php' : 'Location: account.php');
            exit;
        }
    } catch (Throwable $e) {
        // Une requête SQL invalide est traitée comme un échec.
    }

    $message = 'Identifiant ou mot de passe incorrect.';
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion | apptest</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            color: #e8eef7;
            background:
                radial-gradient(circle at 20% 15%, #164b67 0, transparent 35%),
                linear-gradient(135deg, #08111f, #101e32);
        }

        .card {
            width: min(100%, 420px);
            padding: 38px;
            border: 1px solid #34465e;
            border-radius: 18px;
            background: #142237;
            box-shadow: 0 24px 70px #0006;
        }

        .brand {
            color: #59d5c3;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 28px;
            font-size: 29px;
        }

        .intro {
            margin: 0 0 30px;
            color: #aebdd0;
            line-height: 1.5;
        }

        label {
            display: block;
            margin: 18px 0 8px;
            font-size: 14px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #40536b;
            border-radius: 9px;
            outline: none;
            background: #0c192b;
            color: #fff;
            font: inherit;
        }

        input:focus {
            border-color: #59d5c3;
            box-shadow: 0 0 0 3px #59d5c330;
        }

        button {
            width: 100%;
            margin-top: 28px;
            padding: 14px;
            border: 0;
            border-radius: 9px;
            background: #59d5c3;
            color: #092028;
            font: inherit;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover { background: #7be5d5; }

        .message {
            margin: 0 0 22px;
            padding: 13px;
            border-radius: 9px;
            font-size: 14px;
        }

        .success {
            color: #b9f5d0;
            background: #163c32;
        }

        .error {
            color: #ffd0d0;
            background: #4b2530;
        }
    </style>
</head>
<body>
    <main class="card">

        <h1>Connexion</h1>


        <?php if ($message !== ''): ?>
            <p class="message <?= $success ? 'success' : 'error' ?>">
                <?= $message ?>
            </p>
        <?php endif; ?>

        <form method="post">
            <label for="username">Identifiant</label>
            <input id="username" name="username" autocomplete="username" required>

            <label for="password">Mot de passe</label>
            <input id="password" name="password" type="password"
                   autocomplete="current-password" required>

            <button type="submit">Se connecter</button>
        </form>
    </main>
</body>
</html>
