# Installation et fonctionnement de l’application web du laboratoire

L’application `apptest` est hébergée sur Ubuntu `192.168.56.10`, dans `/var/www/html/apptest`, et utilisée depuis Kali `192.168.56.101`. Elle fournit les cibles HTTP des scénarios d’injection SQL et de traversée de répertoires. Les vulnérabilités décrites sont volontaires et servent aux tests du laboratoire.

Le code de l’application est disponible dans [application-test](../application-test/).

## 1. Composants et versions relevées

| Composant | Version du paquet relevée | Fonction |
|---|---|---|
| Apache | `2.4.58-1ubuntu8.15` | Réception des requêtes HTTP |
| PHP 8.3 | `8.3.6-0ubuntu0.24.04.11` | Exécution des pages PHP |
| libapache2-mod-php8.3 | `8.3.6-0ubuntu0.24.04.11` | Intégration de PHP dans Apache |
| php8.3-sqlite3 | `8.3.6-0ubuntu0.24.04.11` | Accès PHP à SQLite |
| sqlite3 | `3.45.1-1ubuntu2.8` | Création et inspection de la base |

L’application utilise l’extension PHP `sqlite3`. Apache charge `php_module` et `mpm_prefork_module` pour exécuter les pages PHP.

## 2. Installation des dépendances

### 2.1. Installation

L’historique APT montre deux installations le **22 septembre 2026** :

| Opération | Début | Fin | Commande enregistrée |
|---|---|---|---|
| Installation Apache | 18:37:51 | 18:37:59 | `apt install apache2 -y` |
| Ajout PHP et SQLite | 19:06:16 | 19:06:29 | `apt install apache2 php libapache2-mod-php php-sqlite3 sqlite3 -y` |

![Historique APT de l’installation Apache, PHP et SQLite](../captures/application-web/historique-installation-apt.png)

L’historique confirme les commandes d’installation d’Apache, puis de PHP et SQLite.

Pour reproduire cette consultation sur Ubuntu :

```bash
sudo zcat -f /var/log/apt/history.log* |
  awk '/^Start-Date:/ { date=$0 }
       /^Commandline:.*apt install apache2/ { print date; print; print "" }'
```

La première commande installe Apache ; la seconde ajoute PHP, son module Apache et SQLite.

- `php` sélectionne la version PHP par défaut d’Ubuntu, ici PHP 8.3.
- `libapache2-mod-php` installe le module permettant à Apache d’exécuter les pages PHP ; l’historique indique `libapache2-mod-php8.3` comme dépendance automatique.
- `php-sqlite3` apporte l’accès à SQLite depuis PHP, nécessaire à `new SQLite3(...)` dans `login.php`.
- `sqlite3` permet de créer et consulter `lab.db` depuis le terminal.
- `-y` accepte automatiquement les confirmations APT.

### 2.2. Reproduction et contrôles

Sur Ubuntu, pour reproduire l’installation si les dépendances sont absentes :

```bash
sudo apt update
sudo apt install apache2 php libapache2-mod-php php-sqlite3 sqlite3 -y
sudo systemctl enable --now apache2
sudo apache2ctl configtest
sudo apache2ctl -M
php -v
php -m
sudo ss -lntp 'sport = :80'
```

Apache doit être actif, écouter sur le port 80 et charger `php_module`. Le contrôle de configuration doit se terminer par `Syntax OK`.

APT installe les versions disponibles dans les dépôts configurés. Le tableau de la section 1 indique celles utilisées dans le laboratoire.

### 2.3. Vérification du service, de l’écoute HTTP et du module PHP

Sur Ubuntu :

```bash
systemctl is-active apache2
sudo ss -lntp 'sport = :80'
sudo apache2ctl -M 2>&1 | grep -E 'php_module|mpm_prefork_module'
```

![Vérification du service Apache, du port HTTP et des modules PHP](../captures/application-web/verification-apache-php.png)

| Résultat visible | Interprétation |
|---|---|
| `active` | Le service `apache2` est en cours d’exécution au moment du contrôle |
| `LISTEN` sur `*:80`, processus `apache2` | Apache possède une écoute TCP sur le port HTTP 80 ; aucune adresse locale unique n’est indiquée dans cette sortie |
| `mpm_prefork_module (shared)` | Apache charge le module de traitement des requêtes par processus prefork |
| `php_module (shared)` | Le module d’exécution PHP est chargé dans Apache |

`ss -lntp` affiche l’écoute sur le port 80 et le processus associé. `apache2ctl -M` liste les modules Apache chargés.

## 3. Fichiers et déploiement

| Fichier déployé | Fonction |
|---|---|
| `login.php` | Formulaire, requête d’authentification et création de session |
| `account.php` | Espace réservé au rôle `user` |
| `admin.php` | Espace réservé au rôle `admin` |
| `download.php` | Lecture d’un fichier choisi par le paramètre GET `file` |
| `files/public.txt` | Fichier servi par défaut par `download.php` |
| `lab.db` | Base SQLite contenant les comptes de connexion |

Depuis la racine d’une copie du dépôt sur Ubuntu :

```bash
sudo install -d -o root -g root -m 0755 /var/www/html/apptest/files
sudo install -o root -g root -m 0644 application-test/*.php /var/www/html/apptest/
```

Installer également le fichier [public.txt](../application-test/files/public.txt), utilisé pour vérifier le téléchargement normal :

```bash
sudo install -o root -g root -m 0644 application-test/files/public.txt /var/www/html/apptest/files/public.txt
```

## 4. Base de données

Le fichier [schema.sql](../application-test/schema.sql) contient le schéma de la table nécessaire au scénario de connexion :

```sql
CREATE TABLE login_accounts (
    username TEXT PRIMARY KEY,
    password TEXT NOT NULL,
    role TEXT NOT NULL
);
```

Pour consulter le schéma de la table d’authentification sur Ubuntu :

```bash
sudo sqlite3 /var/www/html/apptest/lab.db '.schema login_accounts'
```

![Schéma SQLite de la table login_accounts](../captures/application-web/schema-login-accounts.png)

La capture présente la définition `CREATE TABLE login_accounts`, avec les trois colonnes utilisées par `login.php` :

| Colonne | Définition visible | Fonction |
|---|---|---|
| `username` | `TEXT PRIMARY KEY` | Identifiant du compte et clé primaire |
| `password` | `TEXT NOT NULL` | Valeur comparée au mot de passe saisi ; la contrainte interdit `NULL` |
| `role` | `TEXT NOT NULL` | Rôle utilisé pour la redirection et le contrôle des espaces ; la contrainte interdit `NULL` |

Les scripts interprètent les rôles `user` et `admin`. Les mots de passe sont comparés en clair dans la requête volontairement vulnérable de `login.php`.

Pour créer une base absente, depuis la racine du dépôt :

```bash
if sudo test -e /var/www/html/apptest/lab.db; then
    printf 'La base existe déjà : conserver son contenu.\n'
else
    sudo sqlite3 /var/www/html/apptest/lab.db < application-test/schema.sql
fi
```

Sur une base nouvellement créée, ajouter ces deux comptes fictifs pour tester les rôles :

```bash
sudo sqlite3 /var/www/html/apptest/lab.db <<'SQL'
INSERT INTO login_accounts (username, password, role) VALUES
('admin_lab', 'AdminLab123!', 'admin'),
('user_lab', 'UserLab123!', 'user');
SQL
```

Ces comptes servent à la reproduction. Exécuter l’insertion une seule fois.

Permissions observées à reproduire :

```bash
sudo chown www-data:www-data /var/www/html/apptest/lab.db
sudo chmod 0664 /var/www/html/apptest/lab.db
sudo stat -c '%A %U:%G %n' /var/www/html/apptest /var/www/html/apptest/lab.db /var/www/html/apptest/*.php /var/www/html/apptest/files /var/www/html/apptest/files/public.txt
```

Les dossiers appartiennent à `root:root` avec le mode `0755`. Les fichiers PHP et `public.txt` appartiennent à `root:root` avec le mode `0644`. La base appartient à `www-data:www-data` avec le mode `0664`. `login.php` ouvre la base en lecture seule. Les pages conservées n’effectuent aucune insertion ou mise à jour.

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

Le [guide d’utilisation, section 6](07-guide-utilisation.md#6-reproduire-le-scénario-dinjection-sql) présente le test SQLi et les résultats de détection.

## 6. Téléchargement et traversée de répertoires

Code de `download.php` :

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

Le [scénario de traversée](07-guide-utilisation.md#7-reproduire-le-scénario-de-traversée-de-répertoires) utilise ce défaut pour demander `/etc/passwd`.

## 7. Vérifications fonctionnelles et captures

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

Les captures suivantes présentent le formulaire, les espaces associés aux deux rôles et la lecture du fichier public.

### 7.1. Affichage du formulaire de connexion

Ouvrir `http://192.168.56.10/apptest/login.php` dans le navigateur.

![Formulaire de connexion de l’application apptest](../captures/application-web/formulaire-connexion.png)

Le formulaire est accessible à l’adresse du serveur Ubuntu. Il propose les champs Identifiant et Mot de passe et le bouton Se connecter.

### 7.2. Affichage de l’espace administrateur

![Espace administrateur de l’application apptest](../captures/application-web/espace-administrateur.png)

L’espace administrateur affiche « Bienvenue, admin » et le lien de déconnexion.

Cette page exige une session de rôle `admin`. La capture illustre son fonctionnement ; elle n’est pas rattachée au test SQLi du guide d’utilisation.

### 7.3. Affichage de l’espace utilisateur

Se connecter avec un compte de rôle `user`.

![Espace utilisateur de l’application apptest](../captures/application-web/espace-utilisateur.png)

L’espace utilisateur affiche « Bienvenue, c1ph3rz » et le lien de déconnexion.

Pour vérifier les noms et rôles sans afficher les mots de passe, exécuter sur Ubuntu :

```bash
sudo sqlite3 -header -column /var/www/html/apptest/lab.db \
  "SELECT username, role FROM login_accounts;"
```

### 7.4. Lecture normale du fichier public

Ouvrir `http://192.168.56.10/apptest/download.php?file=public.txt` dans le navigateur.

![Lecture du fichier public par download.php](../captures/application-web/telechargement-public.png)

Le navigateur affiche « Fichier public de test » pour `file=public.txt`.

`download.php` lit `/var/www/html/apptest/files/public.txt` et renvoie son contenu en texte. Cette lecture normale sert de référence au test de traversée.
