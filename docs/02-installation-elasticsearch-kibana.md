# Installation et configuration d’Elasticsearch et Kibana

## Rôle dans le projet

Elasticsearch stocke les journaux système et les événements Suricata transmis par syslog-ng. Kibana fournit les recherches, les visualisations, les règles de détection et les actions de notification. Le laboratoire utilise Elasticsearch et Kibana **9.5.4** sur Ubuntu Server.

Les étapes suivantes reprennent l’installation et les réglages du laboratoire. Les secrets sont remplacés par des valeurs à renseigner localement.

## 1. Préparer le dépôt APT Elastic

Sur Ubuntu :

```bash
sudo apt update
sudo apt install ca-certificates curl gnupg apt-transport-https
curl -fsSL https://artifacts.elastic.co/GPG-KEY-elasticsearch | sudo gpg --dearmor -o /usr/share/keyrings/elasticsearch-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/elasticsearch-keyring.gpg] https://artifacts.elastic.co/packages/9.x/apt stable main" | sudo tee /etc/apt/sources.list.d/elastic-9.x.list
sudo apt update
```

La clé permet à APT de vérifier la signature des paquets. Le fichier de dépôt indique où récupérer les versions 9.x. Sur un serveur déjà installé, consulter les sources existantes avant d’ajouter une entrée identique.

### Preuve du dépôt APT configuré

![Source APT Elastic 9.x](../captures/installation/depot-apt-elastic.png)

*Le fichier /etc/apt/sources.list.d/elastic-9.x.list contient le dépôt HTTPS des paquets 9.x, branche stable, composant main. signed-by désigne /usr/share/keyrings/elasticsearch-keyring.gpg pour la vérification des signatures.*

Commande utilisée :

```bash
sudo grep -R -n 'artifacts.elastic.co' /etc/apt/sources.list /etc/apt/sources.list.d/
```

## 2. Installer les versions du laboratoire

```bash
sudo apt install elasticsearch=9.5.4 kibana=9.5.4
dpkg-query -W elasticsearch kibana
```

La première commande fixe les versions ; la seconde les vérifie. Si APT ne trouve pas ces versions, consulter `apt-cache policy elasticsearch kibana`.

![Historique APT Elasticsearch et Kibana](../captures/installation/historique-apt-elastic.png)

*L’historique APT enregistre l’installation des deux paquets 9.5.4 le 16 septembre 2026, de 20:33:20 à 20:37:06 selon l’horloge du journal.*

![Versions installées](../captures/installation/versions-elastic.png)

*Les deux paquets installés portent la version 9.5.4.*

## 3. Configurer et démarrer Elasticsearch

Fichier : `/etc/elasticsearch/elasticsearch.yml`.

[Consulter la configuration relevée](../config/elasticsearch/elasticsearch.yml.example).

![Configuration Elasticsearch](../captures/configuration/elasticsearch.png)

*La configuration active la sécurité et TLS et définit les chemins de données et de journaux.*

| Paramètre | Rôle |
|---|---|
| `path.data` | Répertoire des données indexées : `/var/lib/elasticsearch` |
| `path.logs` | Répertoire des journaux du service : `/var/log/elasticsearch` |
| `xpack.security.enabled` | Active l’authentification et les contrôles d’accès |
| `xpack.security.enrollment.enabled` | Permet l’enrôlement de Kibana et de nouveaux nœuds |
| `xpack.security.http.ssl` | Active HTTPS pour l’API et utilise `certs/http.p12` |
| `xpack.security.transport.ssl` | Configure TLS pour les communications entre nœuds |
| `verification_mode: certificate` | Vérifie le certificat de transport sans contrôler le nom d’hôte |
| `cluster.initial_master_nodes` | Paramètre d’amorçage initial ; la valeur relevée est `server` |
| `http.host: 0.0.0.0` | Écoute HTTP sur toutes les interfaces IPv4 |

Les fichiers PKCS#12 et leurs mots de passe sont propres à l’installation. Les laisser générer par l’auto-configuration de sécurité ; le fichier d’exemple ne suffit pas à recréer ces certificats.

Le nom `server` doit correspondre au nom du nœud lors de l’amorçage. Après formation du cluster, retirer `cluster.initial_master_nodes` de la configuration ; ce paramètre ne doit pas servir lors des redémarrages d’un cluster existant.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now elasticsearch
sudo systemctl status elasticsearch --no-pager
```

![Service Elasticsearch actif](../captures/installation/service-elasticsearch.png)

*active (running) confirme le fonctionnement du service ; enabled indique son activation au démarrage.*

Le résultat attendu est `active (running)`. Pour une nouvelle installation, définir le mot de passe du compte administrateur :

```bash
sudo /usr/share/elasticsearch/bin/elasticsearch-reset-password -u elastic -i
```

Cette commande change le mot de passe : elle n’est pas nécessaire pour contrôler le serveur existant.

Tester l’API depuis Ubuntu, avec saisie interactive du mot de passe :

```bash
sudo curl --cacert /etc/elasticsearch/certs/http_ca.crt -u elastic https://10.0.2.15:9200
```

La réponse attendue est un objet JSON décrivant le nœud et sa version. Le certificat CA permet de valider la connexion TLS.

![Réponse de l’API Elasticsearch](../captures/installation/api-elasticsearch.png)

*L’API retourne le nœud server, le cluster elasticsearch et la version 9.5.4 après authentification.*

## 4. Associer Kibana à Elasticsearch

Sur une nouvelle installation, générer un jeton d’enrôlement :

```bash
sudo /usr/share/elasticsearch/bin/elasticsearch-create-enrollment-token -s kibana
```

Dans `/etc/kibana/kibana.yml`, définir l’adresse d’écoute :

```yaml
server.host: "192.168.56.10"
```

Démarrer Kibana :

```bash
sudo systemctl enable --now kibana
sudo systemctl status kibana --no-pager
```

Ouvrir `http://192.168.56.10:5601` depuis l’hôte et suivre l’assistant avec le jeton d’enrôlement. Si un code de vérification est demandé :

```bash
sudo /usr/share/kibana/bin/kibana-verification-code
```

Terminer l’association puis se connecter avec `elastic` et son mot de passe. L’enrôlement configure la connexion de service et l’autorité de certification. Il ne faut pas recopier le jeton de service d’un autre déploiement.

![Service Kibana actif](../captures/installation/service-kibana.png)

*Kibana est active (running) et enabled.*

![Page de connexion Kibana](../captures/installation/connexion-kibana.png)

*Page de connexion Kibana accessible sur 192.168.56.10:5601 en HTTP.*

## 5. Comprendre la configuration Kibana du laboratoire

[Consulter la configuration sans secrets](../config/kibana/kibana.yml.example).

![Configuration Kibana avec secrets masqués](../captures/configuration/kibana.png)

*Kibana écoute sur 192.168.56.10 et joint Elasticsearch en HTTPS sur 10.0.2.15:9200. Le jeton de service et la clé de chiffrement sont masqués.*

| Paramètre | Valeur ou rôle |
|---|---|
| `server.host` | Écoute sur l’adresse Host-Only `192.168.56.10` |
| `logging.appenders.file` | Écrit les logs au format JSON dans `/var/log/kibana/kibana.log` |
| `logging.root.appenders` | Active les sorties par défaut et fichier |
| `pid.file` | Stocke l’identifiant du processus dans `/run/kibana/kibana.pid` |
| `elasticsearch.hosts` | Joint Elasticsearch en HTTPS sur `10.0.2.15:9200` |
| `elasticsearch.serviceAccountToken` | Authentifie le service Kibana auprès d’Elasticsearch |
| `elasticsearch.ssl.certificateAuthorities` | Désigne le certificat CA utilisé pour vérifier Elasticsearch |
| `xpack.encryptedSavedObjects.encryptionKey` | Chiffre les attributs sensibles des objets enregistrés, notamment ceux des connecteurs |

Adapter le chemin du certificat CA à celui généré sur le serveur.

Sur une nouvelle installation, générer les clés nécessaires avec :

```bash
sudo /usr/share/kibana/bin/kibana-encryption-keys generate
```

Reporter la valeur générée pour `xpack.encryptedSavedObjects.encryptionKey` dans la configuration, puis redémarrer Kibana. Conserver cette clé de manière durable. Sur l’installation existante, son remplacement exige une rotation planifiée pour préserver l’accès aux objets déjà chiffrés.

```bash
sudo systemctl restart kibana
sudo systemctl status kibana --no-pager
```

Le navigateur accède à Kibana en HTTP dans la configuration fournie ; la connexion Kibana–Elasticsearch utilise HTTPS. L’adresse NAT `10.0.2.15` étant attribuée par DHCP, vérifier qu’elle reste celle du serveur après un changement de réseau.

![Accueil Kibana après authentification](../captures/installation/accueil-kibana.png)

*Accueil Kibana après authentification sur 192.168.56.10:5601.*

## 5.1. Appliquer les fichiers de configuration lors de la reproduction

Les fichiers `.example` du dépôt servent de référence. Après installation des paquets, modifier les fichiers système existants plutôt que de les remplacer intégralement : ils contiennent les réglages et certificats générés pour le déploiement.

```bash
sudo cp -a /etc/elasticsearch/elasticsearch.yml /etc/elasticsearch/elasticsearch.yml.bak
sudo cp -a /etc/kibana/kibana.yml /etc/kibana/kibana.yml.bak
sudo nano /etc/elasticsearch/elasticsearch.yml
sudo nano /etc/kibana/kibana.yml
```

Dans Elasticsearch, conserver les paramètres TLS et les fichiers PKCS#12 créés par l'auto-configuration. Reporter les chemins de données/journaux et l'adresse d'écoute documentés. Le nom du nœud et les paramètres d'amorçage doivent correspondre au nouveau déploiement.

Dans Kibana, définir `server.host` avant l'enrôlement. Après association, conserver le jeton de service et le chemin CA générés localement. Reporter le bloc de journalisation, `pid.file` et la clé de chiffrement générée pour cette installation. Les valeurs entre chevrons dans le fichier d'exemple ne sont pas des valeurs utilisables.

### Répertoires et accès du service

Le paquet Debian utilise les comptes de service `elasticsearch` et `kibana`. Les données Elasticsearch résident dans `/var/lib/elasticsearch`, ses journaux dans `/var/log/elasticsearch`. Kibana utilise `/var/lib/kibana` pour ses données et le chemin de journalisation configuré `/var/log/kibana/kibana.log`.

Pour reproduire la sortie fichier, si le répertoire des journaux Kibana n'a pas été créé par le paquet :

```bash
sudo install -d -o kibana -g kibana -m 2750 /var/log/kibana
```

Le compte Kibana doit pouvoir écrire dans ce répertoire et lire le certificat CA indiqué. Le répertoire PID `/run/kibana` est lié au démarrage du service ; il doit être recréé par la configuration du paquet à chaque démarrage. Ne pas appliquer de permissions globales aux répertoires de données ou aux certificats privés.

Après modification sur le déploiement reproduit :

```bash
sudo systemctl restart elasticsearch
sudo systemctl status elasticsearch --no-pager
sudo systemctl restart kibana
sudo systemctl status kibana --no-pager
```

Vérifier ensuite l'API HTTPS et l'accès authentifié à Kibana avec les contrôles et captures des sections précédentes. Les sauvegardes conservent les permissions originales et permettent de revenir aux configurations précédentes.

### Permissions relevées sur le serveur

![Propriétaires et permissions Elastic](../captures/configuration/permissions-elastic.png)

*Les répertoires de données et de journaux appartiennent à leurs comptes de service ; /run/kibana appartient également à kibana.*

| Répertoire | Propriétaire:groupe | Mode observé |
|---|---|---|
| /var/lib/elasticsearch | elasticsearch:elasticsearch | 2750 — drwxr-s--- |
| /var/log/elasticsearch | elasticsearch:elasticsearch | 2750 — drwxr-s--- |
| /var/lib/kibana | kibana:kibana | 2750 — drwxr-s--- |
| /var/log/kibana | kibana:kibana | 2750 — drwxr-s--- |
| /run/kibana | kibana:kibana | 0755 — drwxr-xr-x |

Le mode `2750` réserve l’écriture au propriétaire et conserve le groupe sur les nouveaux fichiers. Les comptes de service doivent pouvoir accéder à leurs données, journaux et certificats.

Commande utilisée :

```bash
sudo stat -c '%A %U:%G %n' /var/lib/elasticsearch /var/log/elasticsearch /var/lib/kibana /var/log/kibana /run/kibana
```

Les autres répertoires sont gérés par les paquets et leurs services. Vérifier leurs propriétés avant toute modification.

## 6. Bilan de validation

| Capture | Lecture attendue |
|---|---|
| Historique APT ou commande d’installation et versions | Versions Elasticsearch et Kibana cohérentes |
| Configuration Elasticsearch | Authentification et TLS activés, chemins de stockage identifiés |
| Configuration Kibana expurgée | Adresse d’écoute et destination Elasticsearch ; secrets masqués |
| État des deux services | Présence de `active (running)` |
| Réponse de l’API Elasticsearch | Nœud joignable et version retournée |
| Connexion et accueil Kibana | Interface joignable depuis l’hôte et accès après authentification confirmé |

En cas d’échec de démarrage :

```bash
sudo journalctl -u elasticsearch -n 50 --no-pager
sudo journalctl -u kibana -n 50 --no-pager
sudo tail -n 50 /var/log/kibana/kibana.log
```

Ces journaux permettent d’identifier une erreur de configuration, de certificat ou d’authentification.

## Références

- [Installation Debian d’Elasticsearch](https://www.elastic.co/docs/deploy-manage/deploy/self-managed/install-elasticsearch-with-debian-package)
- [Installation Debian de Kibana](https://www.elastic.co/docs/deploy-manage/deploy/self-managed/install-kibana-with-debian-package)
- [Paramètres Kibana](https://www.elastic.co/docs/reference/kibana/configuration-reference)
- [Objets enregistrés chiffrés](https://www.elastic.co/docs/deploy-manage/security/secure-saved-objects)

[Retour au README](../README.md)
