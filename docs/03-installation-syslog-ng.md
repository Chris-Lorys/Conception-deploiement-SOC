# Installation et configuration de syslog-ng

## Objectif

syslog-ng collecte les événements locaux du serveur Ubuntu et les événements réseau produits par Suricata. Il sélectionne les messages utiles, les encode en JSON et les transmet à Elasticsearch en HTTPS.

| Source | Événements sélectionnés | Index | Pipeline |
|---|---|---|---|
| s_src : system() et internal() | Facilities auth/authpriv ou programme projet-securite | lab-syslog-system | system-logs |
| Fichier /var/log/suricata/eve.json | event_type alert ou flow | lab-syslog-ids | suricata-json |

Les destinations locales du fichier principal sont conservées. La configuration fournie n’active pas de source réseau distante.

## 1. Installer les paquets

Sur Ubuntu :

```bash
sudo apt update
sudo apt install syslog-ng-core syslog-ng-mod-http
```

Le paquet core fournit le service ; mod-http fournit la destination HTTP utilisée pour envoyer les documents à Elasticsearch. La capture de l’historique APT confirme cette installation le 16 septembre 2026 à 21:18:43, selon l’horloge du journal. Elle indique également le retrait de rsyslog lors de cette installation.

Vérifier les versions :

```bash
dpkg-query -W -f='${Package}\t${Version}\n' syslog-ng-core syslog-ng-mod-http
```

Les captures confirment la version de paquet **4.3.1-2build5** pour les deux composants. La directive `@version: 4.3` désigne la syntaxe de configuration.

## 2. Comprendre le fichier principal

Le fichier `/etc/syslog-ng/syslog-ng.conf` contient notamment :

```conf
@version: 4.3
@include "scl.conf"

source s_src {
    system();
    internal();
};

@include "/etc/syslog-ng/conf.d/*.conf"
```

Cet extrait présente les points de raccordement ; il ne remplace pas le fichier principal complet.

- system() collecte les messages locaux.
- internal() collecte les messages internes de syslog-ng.
- use_dns(no) évite la résolution DNS des sources.
- owner("root"), group("adm") et perm(0640) définissent les propriétés par défaut des fichiers créés.
- Les chemins log relient une source, un filtre et une destination.
- L’inclusion conf.d charge les configurations complémentaires.

Les chemins locaux continuent notamment à écrire les événements d’authentification dans /var/log/auth.log.

## 3. Configurer les deux circuits

Les blocs exacts fournis par le laboratoire sont conservés dans les exemples suivants, avec le mot de passe retiré :

- [Circuit système](../config/syslog-ng/10-elasticsearch-system.conf.example)
- [Circuit Suricata](../config/syslog-ng/20-elasticsearch-suricata.conf.example)

Les noms des fichiers sont proposés pour l’organisation du dépôt ; les noms d’origine n’apparaissent pas dans la sortie concaténée fournie.

Pour reproduire le déploiement, placer les blocs dans des fichiers .conf sous /etc/syslog-ng/conf.d/, remplacer le mot de passe localement et conserver la source s_src du fichier principal. Éviter de définir deux fois les mêmes objets. Le laboratoire existant n’a pas besoin d’être reconfiguré.

## 4. Préparer la connexion sécurisée

Le compte syslog_ingest doit être autorisé à indexer dans les deux index. Les pipelines system-logs, ssh-auth et suricata-json doivent exister.

Sur un déploiement reproduit, installer le certificat public CA au chemin demandé :

```bash
sudo install -d -m 0755 /etc/syslog-ng/certs
sudo install -m 0644 /etc/elasticsearch/certs/http_ca.crt /etc/syslog-ng/certs/elasticsearch-ca.crt
```

La destination utilise peer-verify(yes) pour vérifier TLS. Le certificat du serveur doit être valide pour localhost, nom utilisé dans les URL. Le certificat CA ne contient pas la clé privée du serveur.

La définition exacte du rôle Elasticsearch de syslog_ingest reste à exporter pour compléter la reproduction des droits.

## 5. Circuit des journaux système

Le filtre conserve les facilities auth et authpriv ainsi que le programme exactement nommé projet-securite. Il n’expédie donc pas tous les journaux système.

La destination effectue un POST vers :

```text
https://localhost:9200/lab-syslog-system/_doc?pipeline=system-logs
```

format-json encode les valeurs et construit les champs explicites :

| Champ | Macro source |
|---|---|
| @timestamp | ISODATE |
| host.name | HOST |
| process.name | PROGRAM |
| message | MESSAGE |

Le paramètre --scope none limite le document aux champs indiqués. Le compte syslog_ingest s’authentifie avec user() et password(). L’en-tête Content-Type vaut application/json.

Le pipeline system-logs ajoute event.ingested avec l’horodatage d’ingestion Elasticsearch. Si process.name vaut sshd, il appelle **ssh-auth**, avec un tiret.

ssh-auth traite les messages qui commencent par Failed password for. Son grok extrait user.name, source.ip et source.port, y compris lorsqu’il s’agit d’un utilisateur invalide. Il renseigne ensuite event.outcome à failure, event.category à authentication et event.action à ssh_login_failed. Les autres messages SSH ne sont pas classés comme échecs par cette condition.

## 6. Circuit Suricata

La source suit /var/log/suricata/eve.json. follow-freq(1) fixe un intervalle de suivi d’une seconde. flags(no-parse) conserve la ligne complète dans MESSAGE sans l’interpréter comme un en-tête syslog.

Le filtre recherche les types alert et flow dans le JSON compact. Son nom f_suricata_alert ne signifie pas qu’il sélectionne uniquement les alertes. Le motif dépend de l’espacement du JSON.

La destination effectue un POST vers :

```text
https://localhost:9200/lab-syslog-ids/_doc?pipeline=suricata-json
```

Le document contient la ligne EVE dans message et process.name fixé à suricata.

Le pipeline suricata-json :

1. Renomme l’horodatage fourni par syslog-ng en event.ingested.
2. Décode message dans l’objet suricata.
3. Convertit suricata.timestamp, au format ISO8601, en @timestamp.
4. Copie suricata.src_ip vers source.ip et suricata.dest_ip vers destination.ip.

Dans ce pipeline, event.ingested conserve donc l’heure fournie par syslog-ng, et non directement l’heure d’arrivée dans Elasticsearch. Si la conversion de date échoue, ignore_failure permet de continuer, potentiellement sans @timestamp.

## 7. Envoi et régulation

batch-lines(1) limite le lot à un message, correspondant à l’envoi d’un document par requête _doc. flags(flow-control) régule la collecte lorsque la destination ralentit. Aucun tampon disque explicite n’est configuré dans les blocs transmis ; le contrôle de flux ne garantit pas à lui seul la conservation lors d’un arrêt brutal.

## 8. Valider et lancer le service

Avant d’appliquer une modification :

```bash
sudo syslog-ng -s && printf 'Configuration syslog-ng valide\n'
```

La capture fournie montre une validation réussie avec un avertissement concernant le fichier smart-multi-line.fsm manquant. La collecte du message de test fonctionne ; cet avertissement signale une limite de l’extraction automatique multiligne et doit rester visible dans les preuves.

Après installation ou modification validée :

```bash
sudo systemctl enable syslog-ng
sudo systemctl restart syslog-ng
sudo systemctl status syslog-ng --no-pager -l
```

La capture confirme active (running) et enabled.

Pour diagnostiquer un échec :

```bash
sudo journalctl -u syslog-ng -n 50 --no-pager
```

La validation syntaxique ne vérifie pas les identifiants Elasticsearch, les certificats ni le contenu des pipelines.

## 9. Tester la collecte de bout en bout

```bash
logger -t projet-securite "TEST-COLLECTE-SYSLOG-GITHUB"
```

Dans Discover, sélectionner « Logs sécurité laboratoire » et une période récente. Rechercher :

```text
message : "TEST-COLLECTE-SYSLOG-GITHUB"
```

La capture transmise montre ce message dans lab-syslog-system, avec host.name = server et process.name = projet-securite. Elle valide le trajet du message local jusqu’à Elasticsearch.

## Éléments restant à intégrer

- Captures de l’installation, des configurations, de la validation, du service et du document Discover.
- Fichiers de création des trois pipelines, à partir des exports confirmés.
- Définition du rôle du compte syslog_ingest.

Les captures et exports ont été fournis dans la conversation ; leur publication dans le dépôt est une étape distincte de la rédaction de cette page.

## Référence

[Documentation syslog-ng : destination HTTP](https://syslog-ng.github.io/admin-guide/070_Destinations/081_http/000_http_options.html)

[Retour au README](../README.md)
