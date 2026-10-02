# Analyse des résultats et conclusion

## 1. Bilan du déploiement

Le laboratoire centralise les journaux d’authentification Ubuntu et les événements réseau Suricata dans Elasticsearch, au moyen de syslog-ng. Kibana permet de rechercher les événements, de produire des alertes Elastic Security et de consulter le Dashboard SOC.

Les tests sont réalisés depuis Kali `192.168.56.101` vers Ubuntu `192.168.56.10`. Suricata fonctionne en mode IDS avec AF_PACKET : il observe le trafic et génère des événements, sans bloquer les requêtes. La détection SSH repose sur les journaux de `sshd` ; la reconnaissance réseau repose sur les flux Suricata ; les trois scénarios HTTP utilisent des signatures Suricata.

Pour chaque scénario, l’action **Notifications SOC** écrit dans `lab-notifications`. Le relais Python lancé par un timer systemd transmet ensuite un courriel. Le document indexé et le courriel reçu sont rapprochés par leur date et leur `alert_id`.

Les commandes, captures et preuves détaillées se trouvent dans le [guide d’utilisation](07-guide-utilisation.md). Les paramètres de détection et de notification figurent dans le [guide des détections](06-configuration-detection.md).

## 2. Choix des scénarios et résultats

Les cinq scénarios couvrent trois aspects de la sécurité du serveur : l’authentification, la reconnaissance réseau et les attaques applicatives. Ils mobilisent des sources de journaux et des méthodes de détection différentes : seuil d’échecs SSH, nombre de ports distincts contactés et signatures HTTP. Cette diversité permet de vérifier les différentes fonctions du dispositif avec les deux VM du laboratoire.

### 2.1. Échecs répétés d’authentification SSH

**Menace.** Un attaquant peut multiplier les essais de mots de passe pour accéder à un compte du serveur. S’il réussit, les droits du compte peuvent lui permettre de consulter des données, de modifier des fichiers ou d’exécuter des commandes.

**Justification du choix.** SSH est le service d’administration distante du serveur. Ce scénario permet de vérifier la collecte des journaux d’authentification et une détection fondée sur la répétition : un échec isolé peut provenir d’une erreur de saisie, tandis que plusieurs échecs rapprochés depuis la même source justifient une alerte. Le test utilise cinq saisies erronées pour valider le seuil configuré.

**Lecture des résultats.** Les messages `Failed password`, leur horodatage et `source.ip` relient les tentatives aux cinq événements Discover. L’alerte regroupe ces échecs par source. Elle signale une activité compatible avec une recherche de mot de passe ; aucun accès SSH réussi n’est établi. Voir le [test SSH et ses captures](07-guide-utilisation.md#2-reproduire-le-scénario-ssh).

### 2.2. Reconnaissance réseau avec Nmap

**Menace.** Un scan permet de repérer les ports et services accessibles. Ces informations peuvent servir à sélectionner une cible ou à préparer une attaque contre un service exposé. Un scan peut également correspondre à une opération d’administration autorisée : son contexte doit être examiné.

**Justification du choix.** Ce scénario représente une phase de reconnaissance et vérifie une détection comportementale à partir des flux Suricata. La règle recherche au moins dix événements et dix ports distincts pour un même couple source–destination. Elle complète les signatures HTTP en détectant une activité répartie sur plusieurs connexions.

**Lecture des résultats.** Les flux Discover montrent la source Kali, la destination Ubuntu et les ports contactés. Les 1 000 documents sont des événements réseau ; le nombre de ports distincts est évalué séparément par la règle. L’alerte indique un balayage potentiel, sans démontrer la compromission d’un service. Voir le [test Nmap et ses captures](07-guide-utilisation.md#4-reproduire-le-scénario-de-scan-nmap).

### 2.3. Tentative JNDI de type Log4Shell

**Menace.** Lorsqu’une application utilisant une version vulnérable de Log4j traite une expression JNDI malveillante, elle peut effectuer une résolution non prévue et, selon les conditions, permettre une exécution de code. Un en-tête HTTP peut transporter cette expression jusqu’à une application qui le journalise.

**Justification du choix.** Ce scénario vérifie l’inspection d’un en-tête HTTP et la détection d’un motif associé à une exploitation connue. Il permet de tester la signature Suricata sur le `User-Agent`, puis sa transmission à Elastic Security. Le laboratoire utilise une requête contenant le motif ; il ne déploie pas de service Log4j vulnérable.

**Lecture des résultats.** Le SID **1000002**, les IP et les horodatages relient la requête à l’événement IDS puis à l’alerte. Le résultat valide la détection du motif JNDI et sa notification, sans établir une résolution JNDI ou une exécution de code. Voir le [test JNDI et ses captures](07-guide-utilisation.md#5-reproduire-le-scénario-log4shell--tentative-jndi).

### 2.4. Injection SQL sur le formulaire de connexion

**Menace.** Lorsque les entrées du formulaire sont concaténées à une requête SQL, une valeur malveillante peut en modifier la logique. Dans l’application du laboratoire, l’objectif est de contourner la vérification du mot de passe pour accéder à un compte.

**Justification du choix.** Ce scénario relie une faiblesse concrète du code PHP à sa détection réseau. Il vérifie l’inspection du corps d’une requête HTTP POST, alors que le scénario JNDI porte sur un en-tête. L’application permet aussi de comparer la détection IDS au comportement du formulaire.

**Lecture des résultats.** Le SID **1000004** identifie la signature SQLi. Les IP et les heures permettent de rapprocher l’événement Discover de l’alerte. La réponse **HTTP 302 vers admin.php** montre une redirection ; la capture ne montre pas le contenu de la page après redirection. Voir le [test SQLi et ses captures](07-guide-utilisation.md#6-reproduire-le-scénario-dinjection-sql).

### 2.5. Traversée de répertoires

**Menace.** Un paramètre de téléchargement insuffisamment contrôlé peut permettre de remonter dans l’arborescence avec `../` et de lire des fichiers hors du répertoire autorisé. Cela expose notamment des informations système ou des fichiers de configuration. La cible du test, `/etc/passwd`, contient des informations sur les comptes locaux, sans contenir leurs mots de passe hachés.

**Justification du choix.** Ce scénario vérifie l’inspection de l’URI HTTP et couvre un risque de divulgation de fichiers. Il complète l’injection SQL, qui vise la logique d’authentification. Le script `download.php` fournit un cas reproductible : il concatène le paramètre au chemin de base, et son contrôle `is_file()` ne limite pas la lecture au dossier prévu.

**Lecture des résultats.** L’URI avec cinq séquences `../`, les IP et le SID **100005** relient la requête aux événements et à l’alerte. La capture du test de **21:17 le 1er octobre** montre une réponse HTTP 200, mais pas son corps : le contenu renvoyé ne peut pas être confirmé avec cette capture. Voir le [test de traversée et ses captures](07-guide-utilisation.md#7-reproduire-le-scénario-de-traversée-de-répertoires).

Pour chacun des cinq scénarios, le guide présente également la notification indexée, le journal d’envoi et le courriel reçu. Les interprétations distinguent la menace potentielle, le signal détecté et le résultat effectivement observé.

## 3. Pertinence des journaux collectés

| Source et index | Champs utiles | Justification |
| --- | --- | --- |
| Journaux sshd → lab-syslog-system | event.action, event.outcome, user.name, source.ip | Les échecs d’authentification permettent de compter les tentatives par source et d’identifier le compte visé |
| Événements Suricata flow → lab-syslog-ids | IP source et destination, port destination, horodatage | Les flux permettent de rechercher un nombre élevé de ports distincts contactés, même sans signature IDS correspondante |
| Événements Suricata alert → lab-syslog-ids | Signature, SID, IP, informations HTTP disponibles, horodatage | Les signatures permettent de sélectionner les motifs JNDI, SQLi et ../ et de les rapprocher des requêtes de test |
| Alertes Elastic Security | Nom de règle, priorité, horodatage, champs conservés de l’événement | Elles constituent le résultat des règles de détection et la source des visualisations de synthèse |
| Notifications → lab-notifications | alert_id, règle, scénario, message, horodatage | Elles relient l’action de la règle au message reçu et apportent une explication destinée à l’administrateur |

## 4. Délais observés

Les règles sont planifiées toutes les **minutes**, avec **cinq minutes de recherche supplémentaire**. Le timer de notification est configuré à **dix secondes**. Ces paramètres ne garantissent pas un délai maximal : la collecte, l’indexation, la recherche et l’envoi SMTP interviennent successivement.

| Test | Repère de départ | Alerte Elastic Security | Écart observé |
| --- | --- | --- | --- |
| SSH — 30 septembre | Dernier échec local à 23:47:05 | 23:47:56.613 | Environ 51,6 s |
| JNDI — 1er octobre | Événement IDS à 11:43:03.369 | 11:43:48.513 | 45,144 s |
| SQLi — 1er octobre | Événement IDS à 13:06:33.927 | 13:06:47.900 | 13,973 s |
| Traversée — 1er octobre | Événement IDS à 21:17:39.468 | 21:17:59.160 | 19,692 s |

Les heures du tableau sont exprimées en **UTC−4**. Les écarts SSH et HTTP utilisent des repères de départ différents : le dernier échec local pour SSH, l’événement IDS indexé pour HTTP. Ils ne constituent pas une comparaison de performances entre scénarios.

Pour la traversée, le relais annonce l’envoi à **21:18:22**, soit environ **42,5 secondes après l’événement IDS**. La messagerie affiche la réception à 21:18 avec une précision à la minute. Un délai exact de livraison ne peut pas être calculé à partir de cette capture.

Ces mesures portent sur les essais documentés. Elles ne permettent pas d’estimer un délai moyen ou une performance sous charge.

## 5. Apport du dashboard

Le [Dashboard SOC](08-visualisations-kibana.md) présente cinq panneaux : total, répartition par règle, évolution temporelle, adresses sources et documents récents.

Les captures du **1er octobre 2026** montrent **huit alertes** : quatre de traversée, deux JNDI, une de scan et une SQLi. Le total concorde avec la répartition, les barres temporelles et les huit alertes associées à Kali. Le test SSH du **30 septembre** se situe hors de cette sélection journalière.

Cette synthèse aide à choisir une période et une source à examiner. L’administrateur doit ensuite consulter l’alerte et ses événements d’origine. Le nombre d’alertes ne mesure ni la gravité des attaques, ni le nombre de compromissions réussies.

## 6. Limites du projet

- **Périmètre réduit** : deux VM, une source de test et un serveur. Les essais ne mesurent pas la capacité de traitement sur un réseau comprenant de nombreux équipements.
- **Détection sans prévention** : le fonctionnement IDS de Suricata produit une alerte, sans empêcher l’application de traiter la requête.
- **Couverture ciblée** : les signatures et seuils répondent aux scénarios retenus. Des variantes de charges, un scan lent ou des tentatives réparties entre plusieurs sources peuvent nécessiter d’autres critères.
- **Faux positifs et faux négatifs non quantifiés** : les captures valident des cas précis ; elles ne fournissent pas de taux de détection sur un jeu de trafic bénin et malveillant.
- **Visibilité HTTP** : les signatures utilisées inspectent le trafic HTTP des tests. Une requête chiffrée n’offre pas au capteur la même visibilité sur son URI, ses en-têtes et son corps.
- **Dépendances de notification** : la réception dépend d’Elasticsearch, du relais, de la connectivité SMTP et de la messagerie. Les journaux du service et le courriel doivent être contrôlés séparément.
- **Disponibilité** : les principaux composants sont réunis sur Ubuntu. Aucun test de panne, de reprise ou de haute disponibilité n’est présenté.
- **Application volontairement vulnérable** : elle fournit des cibles reproductibles dans le réseau du laboratoire ; ses requêtes SQL et ses accès aux fichiers nécessiteraient des protections pour un autre usage.

## 7. Améliorations possibles

Les améliorations suivantes prolongeraient le travail réalisé :

| Amélioration | Intérêt |
| --- | --- |
| Répéter les tests avec du trafic bénin et des variantes de charges | Évaluer les faux positifs, les détections manquées et les délais |
| Ajuster les signatures et les seuils | Étendre la couverture sans multiplier les alertes inutiles |
| Tester la reprise du relais après une interruption SMTP | Vérifier la continuité des notifications |
| Définir la conservation des index et la sauvegarde des configurations | Maîtriser le stockage et faciliter la restauration |
| Comparer l’application vulnérable avec une version corrigée | Mesurer l’effet des requêtes SQL préparées et du contrôle des chemins |

## 8. Perspectives et veille technologique

La veille doit suivre les composants effectivement utilisés : avis de sécurité et notes de version de Suricata, syslog-ng, Elasticsearch, Kibana, Apache et PHP. Les règles Suricata et les champs utilisés par Elastic Security doivent également être réévalués lorsque les logiciels ou les signatures évoluent.

Une mise à jour pourra être étudiée dans un instantané des VM : relever les versions, appliquer le changement, rejouer les cinq scénarios, contrôler les pipelines, les alertes, les visualisations et les courriels. Cette procédure permettrait de vérifier la compatibilité avant de retenir la nouvelle configuration.

La veille sur les techniques de reconnaissance, les injections et les contournements de signatures pourra servir à enrichir les tests. Toute nouvelle variante devra être accompagnée de sa commande, de ses événements et de ses résultats propres.

## 9. Conclusion

Le projet démontre une chaîne fonctionnelle de collecte, de détection, de visualisation et de notification pour les cinq scénarios retenus. Les preuves relient les événements aux alertes, puis les notifications indexées aux courriels reçus.

Le laboratoire réunit les fonctions attendues : collecte centralisée, détection de cinq scénarios, consultation des résultats et envoi d’alertes à l’administrateur. Les configurations, commandes et captures permettent de reprendre le déploiement et les essais.
