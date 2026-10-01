# Installation et configuration de Suricata

## 1. Rôle dans le SOC

Suricata inspecte le trafic du réseau de laboratoire sur `enp0s8` et écrit ses événements dans `/var/log/suricata/eve.json`. syslog-ng collecte les événements `alert` et `flow`, puis les transmet à Elasticsearch dans `lab-syslog-ids` via le pipeline `suricata-json`. Voir le [guide syslog-ng](03-installation-syslog-ng.md).

Le déploiement documenté fonctionne en **IDS** : les règles utilisent l'action `alert`. Les captures ne démontrent ni un mode inline ni un blocage IPS. Une alerte signale une correspondance avec une signature ; elle ne prouve pas que l'exploitation a réussi.

Prérequis : Ubuntu 24.04, accès sudo, interface `enp0s8` sur le réseau 192.168.56.0/24, serveur web du laboratoire sur 192.168.56.10:80. Les tests doivent être réalisés dans ce laboratoire.

## 2. Installer les paquets

Sur Ubuntu :

```bash
sudo apt update
sudo apt install -y suricata suricata-update jq
suricata -V
dpkg-query -W suricata suricata-update jq
```

L'historique APT fourni atteste l'installation le **16 septembre 2026, de 23:09:46 à 23:10:03**, avec la commande `apt install -y suricata suricata-update jq`. Les versions enregistrées sont `suricata 1:7.0.3-1build3` et `suricata-update 1.3.0-2`. La version de jq n'apparaît pas dans cet extrait. Une installation ultérieure peut fournir d'autres versions.

![Version de Suricata](images/suricata-version.png)

**Lecture :** la capture confirme `Suricata version 7.0.3 RELEASE`. Pour reproduire cette capture : `suricata -V`.

Pour retrouver la preuve d'installation :

```bash
sudo zcat -f /var/log/apt/history.log* | grep -B 2 -A 3 -E '^Commandline:.*install.*suricata'
```

## 3. Configurer l'interface et les réseaux

Sauvegarder le fichier avant de modifier les sections existantes, sans dupliquer les clés YAML :

```bash
ip -br address
sudo cp -a /etc/suricata/suricata.yaml /etc/suricata/suricata.yaml.bak
sudo nano /etc/suricata/suricata.yaml
```

Dans la section `af-packet`, configurer :

```yaml
af-packet:
  - interface: enp0s8
    cluster-id: 99
    cluster-type: cluster_flow
    defrag: yes
  - interface: default
```

La capture montre également une entrée `default` ; elle ne prouve pas qu'une deuxième interface est surveillée. Le réseau d'attaque atteint le serveur par `enp0s8`.

![Configuration AF_PACKET](images/suricata-af-packet.png)

**Lecture :** `enp0s8` est l'interface choisie ; `cluster_flow` répartit les paquets par flux. Pour afficher cette section :

```bash
sudo grep -A 28 -n '^af-packet:' /etc/suricata/suricata.yaml
```

La valeur réellement observée de `vars.address-groups.HOME_NET` est :

```yaml
HOME_NET: "[192.168.0.0/16,10.0.0.0/8,172.16.0.0/12]"
```

Elle inclut 192.168.56.10 et couvre tous les réseaux privés indiqués. Une restriction à `[192.168.56.0/24]` serait une amélioration possible, pas la configuration montrée.

## 4. Installer et déclarer les règles

Sur une nouvelle installation, générer le jeu de règles avec :

```bash
sudo suricata-update
sudo install -d -m 0755 /etc/suricata/rules
```

La commande standard de suricata-update récupère ET Open et génère `/var/lib/suricata/rules/suricata.rules`. Le résultat d'une mise à jour n'est pas fourni parmi ces captures ; sa version et son contenu peuvent évoluer. Conserver une copie datée du jeu utilisé pour une reproduction exacte.

Depuis la racine du dépôt, installer les règles du projet :

```bash
sudo install -m 0644 config/suricata/local.rules /etc/suricata/rules/local.rules
```

Dans `/etc/suricata/suricata.yaml`, conserver :

```yaml
default-rule-path: /var/lib/suricata/rules
rule-files:
  - suricata.rules
  - /etc/suricata/rules/local.rules
```

![Réseaux et fichiers de règles](images/suricata-reseau-regles.png)

**Lecture :** la capture prouve la valeur de HOME_NET, le dossier du jeu généré et le chargement du fichier local. Commandes pour la reproduire :

```bash
sudo grep -n 'HOME_NET:' /etc/suricata/suricata.yaml
sudo grep -n '^default-rule-path:' /etc/suricata/suricata.yaml
sudo grep -A 8 -n '^rule-files:' /etc/suricata/suricata.yaml
```

![Règles locales du laboratoire](images/suricata-regles-locales.png)

**Lecture :** les règles visibles correspondent au fichier [local.rules](../config/suricata/local.rules). Pour les afficher : `sudo cat /etc/suricata/rules/local.rules`.

| Scénario | SID / révision | Conditions principales |
| --- | --- | --- |
| Tentative Log4Shell | 1000002 / 1 | HTTP vers HOME_NET:80 ; motif JNDI dans User-Agent ; comparaison insensible à la casse |
| Tentative d'injection SQL | 1000004 / 2 | HTTP vers 192.168.56.10:80 ; URI contenant /apptest/login.php ; corps contenant username= et un motif SQL de la règle |
| Traversée de répertoires | 100005 / 1 | HTTP vers 192.168.56.10:80 ; URI brute contenant ../ |

Ces signatures couvrent les motifs définis, pas toutes les variantes d'attaque. La règle SQL ne contient pas de transformation `url_decode` ; la règle de traversée recherche le motif littéral dans l'URI brute. Le chiffrement HTTPS empêche leur inspection HTTP sans accès au trafic déchiffré. La règle JNDI détecte un indicateur dans un en-tête, sans démontrer une vulnérabilité Log4j du serveur.

## 5. Activer la sortie EVE JSON

Dans la section `outputs` existante, vérifier l'entrée `eve-log` :

```yaml
- eve-log:
    enabled: yes
    filetype: regular
    filename: eve.json
    pcap-file: false
```

Cet extrait est partiel : conserver les autres paramètres de l'entrée, notamment `types`. Les types `alert` et `flow` doivent être activés pour alimenter les événements sélectionnés par syslog-ng. L'extrait fourni confirme enabled, filetype et filename, mais ne montre pas la liste des types.

```bash
sudo grep -A 110 -n 'eve-log:' /etc/suricata/suricata.yaml
```

Le fichier attendu dans ce laboratoire est `/var/log/suricata/eve.json`. Contrôler sa présence et son contenu après génération de trafic :

```bash
sudo ls -lh /var/log/suricata/eve.json
sudo tail -n 100 /var/log/suricata/eve.json | jq -c 'select(.event_type == "alert" or .event_type == "flow")'
```

## 6. Valider et lancer

```bash
sudo suricata -T -c /etc/suricata/suricata.yaml
```

![Validation de la configuration](images/suricata-validation.png)

**Lecture :** `Configuration provided was successfully loaded. Exiting.` confirme la validation de la configuration chargée. Ce contrôle ne démontre pas à lui seul la détection d'une attaque.

Après validation :

```bash
sudo systemctl enable suricata
sudo systemctl restart suricata
sudo systemctl status suricata --no-pager -l
sudo tail -n 30 /var/log/suricata/suricata.log
```

![Service Suricata actif](images/suricata-service.png)

**Lecture :** le service est `enabled` et `active (running)`. La commande du processus comporte `--af-packet -c /etc/suricata/suricata.yaml`. La capture indique environ 629 Mo de mémoire à cet instant ; ce n'est pas une mesure de performance générale. Pour reproduire : `sudo systemctl status suricata --no-pager -l`.

## 7. Vérifier les alertes et la collecte

Pendant l'exécution d'un scénario HTTP du laboratoire :

```bash
sudo tail -f /var/log/suricata/eve.json | jq 'select(.event_type == "alert") | {timestamp,src_ip,dest_ip,alert}'
```

Identifier l'heure, l'adresse source, la destination et `alert.signature_id`, puis rechercher le même événement dans Kibana avec la vue de données couvrant `lab-syslog-ids` et une période correspondant au test. Après le pipeline, les champs utiles sont `@timestamp`, `source.ip`, `destination.ip`, `suricata.event_type` et `suricata.alert.signature_id`.

Exemple de filtre KQL pour les règles locales :

```text
suricata.event_type : "alert" and suricata.alert.signature_id : (1000002 or 1000004 or 100005)
```

Une absence d'événements appelle un contrôle successif de l'interface, du trafic HTTP:80, des règles chargées, d'EVE, du service syslog-ng et de la destination Elasticsearch. Le statut actif seul ne prouve pas que toute la chaîne fonctionne. Les captures de ce guide attestent l'installation/configuration ; les preuves d'alertes et leur lecture doivent accompagner les scénarios concernés.

## Référence

[Guide officiel Suricata 7.0.3](https://docs.suricata.io/en/suricata-7.0.3/quickstart.html) : configuration de l'interface, gestion des signatures, service et lecture EVE. Les paramètres et captures ci-dessus décrivent le laboratoire effectivement fourni.
