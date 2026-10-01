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

**État observé :** les journaux du 1er octobre, de 09:49:45 à 09:50:51, montrent des passages réguliers sans erreur signalée, avec `Aucune nouvelle notification`. Ils attestent le fonctionnement périodique, mais pas un envoi à ces heures. Le document de notification du test et la preuve de réception du courriel restent à intégrer.

## 3. Critères de validation du scénario SSH

| Étape | Preuve attendue |
| --- | --- |
| Génération | Confirmée : cinq refus d'authentification sur Kali |
| Journalisation | Confirmée : cinq messages Failed password pour admin, depuis 192.168.56.101 |
| Collecte et normalisation | Confirmées : cinq documents Discover contenant les champs SSH attendus |
| Détection | Confirmée dans la vue Alertes : règle SSH, IP du test et horodatage concordants |
| Notification | Courriel reçu, si l'action SSH est configurée |

Les captures confirment le contrôle du compte, la génération des cinq échecs, leur journalisation sur Ubuntu et la collecte des cinq documents normalisés dans Discover. La génération d'une alerte SSH est également attestée dans la vue Alertes. Les détails confirment le groupe source et le seuil configuré. La preuve de notification reste à intégrer.
