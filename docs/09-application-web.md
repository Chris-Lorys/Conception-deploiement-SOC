# Installation et fonctionnement de l’application web du laboratoire

L’application `apptest` est hébergée sur Ubuntu `192.168.56.10`, dans `/var/www/html/apptest`, et utilisée depuis Kali `192.168.56.101`. Elle fournit les cibles HTTP des scénarios d’injection SQL et de traversée de répertoires. Les vulnérabilités décrites sont volontaires et servent aux tests du laboratoire.

Le code publié dans [application-test](../application-test/) provient du relevé des fichiers transmis le 1er octobre 2026. Le schéma de la base et les permissions ont également été relevés. Les commandes d’installation ci-dessous constituent une procédure de reproduction ; l’historique exact de l’installation Apache/PHP/SQLite et les captures du site restent à ajouter.

## 1. Composants et versions relevées

| Composant | Version du paquet relevée | Fonction |
|---|---|---|
| Apache | `2.4.58-1ubuntu8.15` | Réception des requêtes HTTP |
| PHP 8.3 | `8.3.6-0ubuntu0.24.04.11` | Exécution des pages PHP |
| libapache2-mod-php8.3 | `8.3.6-0ubuntu0.24.04.11` | Intégration de PHP dans Apache |
| php8.3-sqlite3 | `8.3.6-0ubuntu0.24.04.11` | Accès PHP à SQLite |
| sqlite3 | `3.45.1-1ubuntu2.8` | Création et inspection de la base |

`php -m` montre notamment `session`, `sqlite3` et `pdo_sqlite`. Le code utilise la classe `SQLite3`, et non PDO. `apache2ctl -M` montre `php_module` et `mpm_prefork_module`, cohérents avec l’exécution PHP par le module Apache. La sortie CLI ne suffit pas, à elle seule, à prouver l’exécution PHP dans une réponse HTTP.

La longue liste obtenue avec `dpkg-query -W 'php*'` inclut des noms sans version : elle ne constitue pas une liste de tous les modules installés et utilisables. Les versions renseignées et les modules effectivement chargés sont les éléments retenus ici.

## 2. Installation des dépendances

Sur Ubuntu, si les dépendances sont absentes :

```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php8.3 php8.3-cli php8.3-sqlite3 sqlite3
sudo systemctl enable --now apache2
sudo apache2ctl configtest
sudo apache2ctl -M
php -v
php -m
sudo ss -lntp 'sport = :80'
```

Apache doit être actif, écouter sur le port 80 et charger `php_module`. Le contrôle de configuration doit se terminer par `Syntax OK`. L’avertissement `AH00558` présent dans le relevé concerne l’absence de nom global `ServerName` ; il ne démontre pas un échec de chargement des modules.

Les commandes installent les versions disponibles dans les dépôts Ubuntu configurés. Le tableau précédent indique les versions observées sur la VM ; il ne garantit pas leur disponibilité ultérieure.

## 3. Fichiers et déploiement

| Fichier déployé | Fonction |
|---|---|
| `login.php` | Formulaire, requête d’authentification et création de session |
| `account.php` | Espace réservé au rôle `user` |
| `admin.php` | Espace réservé au rôle `admin` |
| `user.php` | Recherche dans la table `users` par le paramètre GET `id` |
| `download.php` | Lecture d’un fichier choisi par le paramètre GET `file` |
| `files/public.txt` | Fichier servi par défaut par `download.php` |
| `lab.db` | Base SQLite contenant les deux tables |

Depuis la racine d’une copie du dépôt sur Ubuntu :

```bash
sudo install -d -o root -g root -m 0755 /var/www/html/apptest/files
sudo install -o root -g root -m 0644 application-test/*.php /var/www/html/apptest/
```

Les cinq fichiers PHP publiés conservent le code et l’interface transmis. Le contenu réel de `public.txt` n’a pas été fourni. Pour une reproduction, créer un fichier de test explicite :

```bash
printf 'Fichier public du laboratoire apptest.\n' | sudo tee /var/www/html/apptest/files/public.txt
sudo chown root:root /var/www/html/apptest/files/public.txt
sudo chmod 0644 /var/www/html/apptest/files/public.txt
```

Ce texte est un exemple de reproduction, pas une copie attestée du contenu existant.

## 4. Base de données

Le fichier [schema.sql](../application-test/schema.sql) contient le schéma relevé :

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    username TEXT,
    email TEXT,
    role TEXT
);
CREATE TABLE login_accounts (
    username TEXT PRIMARY KEY,
    password TEXT NOT NULL,
    role TEXT NOT NULL
);
```

Les tables sont distinctes : `login.php` consulte `login_accounts`, tandis que `user.php` consulte `users`. Le code ne les synchronise pas. Les mots de passe de `login_accounts` sont comparés directement en texte dans la requête vulnérable.

Pour créer une base absente, depuis la racine du dépôt :

```bash
if sudo test -e /var/www/html/apptest/lab.db; then
    printf 'La base existe déjà : conserver son contenu.\n'
else
    sudo sqlite3 /var/www/html/apptest/lab.db < application-test/schema.sql
fi
```

Les lignes de la base actuelle n’ont pas été transmises. Sur une base de reproduction nouvellement créée, les données fictives suivantes permettent de tester les deux rôles :

```bash
sudo sqlite3 /var/www/html/apptest/lab.db <<'SQL'
INSERT INTO login_accounts (username, password, role) VALUES
('admin_lab', 'AdminLab123!', 'admin'),
('user_lab', 'UserLab123!', 'user');
INSERT INTO users (id, username, email, role) VALUES
(1, 'admin_lab', 'admin@example.test', 'admin'),
(2, 'user_lab', 'user@example.test', 'user');
SQL
```

Ces identifiants sont des exemples fictifs ; ils ne décrivent pas les comptes de la VM actuelle. L’insertion n’est pas à répéter sur une base déjà initialisée.

Permissions observées à reproduire :

```bash
sudo chown www-data:www-data /var/www/html/apptest/lab.db
sudo chmod 0664 /var/www/html/apptest/lab.db
sudo stat -c '%A %U:%G %n' /var/www/html/apptest /var/www/html/apptest/lab.db /var/www/html/apptest/*.php /var/www/html/apptest/files /var/www/html/apptest/files/public.txt
```

Les dossiers appartiennent à `root:root` avec le mode `0755`. Les fichiers PHP et `public.txt` appartiennent à `root:root` avec le mode `0644`. La base appartient à `www-data:www-data` avec le mode `0664`. `login.php` ouvre la base en lecture seule ; `user.php` utilise le mode d’ouverture par défaut. Aucun des fichiers transmis n’effectue d’insertion ou de mise à jour.

## 5. Connexion et contrôle des rôles

`login.php` démarre une session, lit `username` et `password` du POST, puis exécute :

```php
$query = "SELECT username, role FROM login_accounts WHERE username = '$username' AND password = '$password' LIMIT 1";
```

Si une ligne est renvoyée, le script renouvelle l’identifiant de session, enregistre le nom et le rôle, puis redirige vers `admin.php` pour le rôle `admin`, ou vers `account.php` autrement. `admin.php` exige exactement le rôle `admin` ; `account.php` exige exactement le rôle `user`. Les noms affichés sont échappés avec `htmlspecialchars`. Le lien `login.php?logout=1` détruit la session et redirige vers le formulaire.

La faiblesse est la concaténation des entrées dans le SQL. Avec le nom `' OR 1=1 -- `, la condition devient :

```sql
SELECT username, role FROM login_accounts WHERE username = '' OR 1=1 -- ' AND password = 'test' LIMIT 1
```

Le commentaire neutralise la vérification du mot de passe et le `LIMIT 1`. La condition vraie peut renvoyer des comptes, dont PHP lit la première ligne. Sans ordre explicite, ce payload ne garantit pas que cette ligne possède le rôle `admin`. Le rôle enregistré provient de la ligne renvoyée par SQLite.

Le [guide d’utilisation, section 6](06-guide-utilisation.md), présente le test SQLi, l’événement IDS, l’alerte et le courriel. La redirection observée vers `admin.php` doit être distinguée de la preuve d’affichage de la page avec la session conservée.

`user.php` contient une autre concaténation SQL : `WHERE id = $id`, à partir de GET `id`. Cette page ne vérifie pas la session. Elle correspond à une recherche utilisateur distincte du scénario retenu sur le formulaire de connexion. La règle SID `1000004` cible `/apptest/login.php` ; sa couverture ne doit pas être extrapolée à `user.php`.

## 6. Téléchargement et traversée de répertoires

Le code transmis est :

```php
$file = $_GET['file'] ?? 'public.txt';
$path = __DIR__ . '/files/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit('Fichier introuvable');
}

header('Content-Type: text/plain; charset=utf-8');
readfile($path);
```

Le paramètre est ajouté directement au chemin de `files/`, sans vérifier que le chemin résolu reste dans ce dossier. `is_file()` contrôle le type du chemin ; il n’impose aucune frontière de répertoire. Ainsi, `../login.php` désigne `/var/www/html/apptest/login.php`. Si ce fichier est accessible au processus Apache, `readfile()` en renvoie le contenu brut, sans exécuter son PHP.

Le dossier `/var/www/lab-private` est absent du relevé actuel. La documentation ne suppose donc pas l’existence d’un `secret.txt` à cet emplacement. Une réponse 404 peut signaler un chemin inexistant ; elle ne prouve pas la prévention de toutes les traversées. Une alerte Suricata prouve la détection du motif dans la requête, tandis que la réponse HTTP sert à évaluer le résultat côté application.

## 7. Vérifications fonctionnelles et captures à compléter

Sur Ubuntu :

```bash
sudo systemctl status apache2 --no-pager -l
for fichier in /var/www/html/apptest/*.php; do
    php -l "$fichier"
done
sudo sqlite3 /var/www/html/apptest/lab.db '.schema'
```

Depuis Kali :

```bash
curl -i --max-time 10 http://192.168.56.10/apptest/login.php
curl -i --max-time 10 http://192.168.56.10/apptest/admin.php
curl -i --max-time 10 http://192.168.56.10/apptest/account.php
curl -i --max-time 10 'http://192.168.56.10/apptest/download.php?file=public.txt'
```

Le formulaire doit être rendu en HTML. Sans session authentifiée, les deux espaces doivent rediriger vers `login.php`. Le téléchargement normal doit renvoyer le contenu de `public.txt` en texte. Dans un navigateur, tester les deux comptes de laboratoire, relever la page obtenue puis la déconnexion.

| Capture attendue | Ce qu’elle vérifie |
|---|---|
| Historique APT Apache/PHP/SQLite | Commandes et date d’installation réelle |
| Service Apache, port 80 et modules | État du serveur et intégration PHP |
| Schéma SQLite et permissions | Structure et accès aux fichiers |
| Formulaire de connexion | Rendu de l’application |
| Connexion normale puis espace de chaque rôle | Fonctionnement des sessions et contrôles de rôle |
| Téléchargement de `public.txt` | Fonctionnement normal avant le test de traversée |

Le relevé textuel transmis établit le code, le schéma, les versions indiquées, les modules listés et les permissions. Les vérifications HTTP ci-dessus sont des étapes à réaliser ; leurs résultats ne sont pas encore présentés comme des preuves.
