# Analyse des résultats et conclusion

## 1. Bilan du déploiement

Le laboratoire centralise les journaux d’authentification Ubuntu et les événements réseau Suricata dans Elasticsearch, au moyen de syslog-ng. Kibana permet de rechercher les événements, de produire des alertes Elastic Security et de consulter le Dashboard SOC.

Les tests sont réalisés depuis Kali `192.168.56.101` vers Ubuntu `192.168.56.10`. Suricata fonctionne en mode IDS avec AF_PACKET : il observe le trafic et génère des événements, sans bloquer les requêtes. La détection SSH repose sur les journaux de `sshd` ; la reconnaissance réseau repose sur les flux Suricata ; les trois scénarios HTTP utilisent des signatures Suricata.

Pour chaque scénario, l’action **Notifications SOC** écrit dans `lab-notifications`. Le relais Python lancé par un timer systemd transmet ensuite un courriel. Le document indexé et le courriel reçu sont rapprochés par leur date et leur `alert_id`.

Les commandes, captures et preuves détaillées se trouvent dans le [guide d’utilisation](07-guide-utilisation.md). Les paramètres de détection et de notification figurent dans le [guide des détections](06-configuration-detection.md).

## 2. Choix des scénarios et résultats

Les cinq scénarios couvrent des comportements différents : répétition d’échecs d’authentification, reconnaissance de services et requêtes HTTP contenant des motifs suspects. Ils permettent de vérifier des détections par seuil, par cardinalité et par signature dans le même laboratoire.

| Scénario et justification | Résultat documenté | Portée de la preuve |
| --- | --- | --- |
| **SSH** : rechercher une répétition d’échecs depuis une même source, représentative d’une tentative de deviner un mot de passe | Cinq échecs collectés, alerte Elastic Security, notification indexée et courriel reçu | La répétition est détectée ; aucun accès SSH réussi n’est établi |
| **Nmap** : repérer une source contactant de nombreux ports d’un serveur, comportement de reconnaissance préalable | Scan Kali, 1 000 événements de flux dans Discover, alerte par cardinalité, notification et courriel | Les flux et l’alerte concordent ; le compteur exact de ports distincts agrégés n’est pas visible dans les détails fournis |
| **Tentative JNDI de type Log4Shell** : identifier une chaîne caractéristique dans un en-tête HTTP | Motif envoyé dans le User-Agent, signature SID 1000002 collectée, alerte, notification et courriel | La chaîne JNDI est détectée ; le test ne démontre pas l’exploitation d’un service Log4j vulnérable |
| **Injection SQL** : observer une charge visant la requête du formulaire de connexion de l’application de test | Réponse HTTP 302 vers admin.php, signature SID 1000004 collectée, alerte, notification et courriel | La redirection et la détection sont visibles ; la capture ne montre pas le contenu de la page après redirection |
| **Traversée de répertoires** : détecter une requête cherchant un fichier hors du répertoire prévu | Requête avec cinq séquences ../ vers /etc/passwd, HTTP 200, signature SID 100005 collectée, alerte, notification et courriel | La capture de cette tentative montre les en-têtes HTTP, sans le corps ; elle ne confirme donc pas le contenu renvoyé |

Chaque ligne résume le test présenté dans sa section du guide. Pour la traversée, les preuves retenues concernent **la tentative du 1er octobre 2026 à 21:17 en UTC−4**. Les alertes d’autres essais visibles dans le dashboard ne sont pas attribuées à cette tentative.

## 3. Pertinence des journaux collectés

| Source et index | Champs utiles | Justification |
| --- | --- | --- |
| Journaux sshd → lab-syslog-system | event.action, event.outcome, user.name, source.ip | Les échecs d’authentification permettent de compter les tentatives par source et d’identifier le compte visé |
| Événements Suricata flow → lab-syslog-ids | IP source et destination, port destination, horodatage | Les flux permettent de rechercher un nombre élevé de ports distincts contactés, même sans signature IDS correspondante |
| Événements Suricata alert → lab-syslog-ids | Signature, SID, IP, informations HTTP disponibles, horodatage | Les signatures permettent de sélectionner les motifs JNDI, SQLi et ../ et de les rapprocher des requêtes de test |
| Alertes Elastic Security | Nom de règle, priorité, horodatage, champs conservés de l’événement | Elles constituent le résultat des règles de détection et la source des visualisations de synthèse |
| Notifications → lab-notifications | alert_id, règle, scénario, message, horodatage | Elles relient l’action de la règle au message reçu et apportent une explication destinée à l’administrateur |

Les journaux, les alertes et les notifications correspondent à des étapes différentes. Leurs nombres ne sont pas interchangeables : les 1 000 flux du scan ne représentent pas 1 000 alertes, et un document de notification ne prouve pas à lui seul la réception d’un courriel.

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

Ces observations montrent une détection et une notification à l’échelle de quelques dizaines de secondes pour ces essais. Elles ne représentent ni une moyenne sur des tests répétés, ni une garantie sous charge. Aucun délai supplémentaire n’est attribué au scan Nmap sans un repère de départ comparable.

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

Le dashboard enregistré et ses dépendances sont désormais conservés dans [dashboard-soc.ndjson](../exports-kibana/dashboard-soc.ndjson). La structure du fichier et ses références ont été vérifiées ; une réimportation reste à tester. Les pistes suivantes prolongent le déploiement existant et restent à réaliser.

| Priorité | Amélioration | Validation attendue |
| --- | --- | --- |
| 1 | Tester la réimportation de l’export du dashboard avec ses dépendances | Réimporter les objets et retrouver les mêmes champs, filtres et panneaux |
| 2 | Répéter chaque scénario avec des repères horaires comparables et ajouter des tests bénins | Mesurer les délais et relever les détections attendues, manquées ou indésirables |
| 3 | Tester les variantes des charges HTTP et ajuster les signatures et seuils | Vérifier la couverture sans augmenter inutilement les faux positifs |
| 4 | Tester le relais en cas d’échec SMTP, de redémarrage et de notification déjà traitée | Vérifier la reprise et l’absence de renvois indésirables |
| 5 | Documenter la sauvegarde des configurations, de l’état du relais et la conservation des index | Restaurer un état utilisable et maîtriser l’occupation du disque |
| 6 | Après conservation des preuves, comparer des versions corrigées de l’application | Utiliser des requêtes SQL préparées et limiter les chemins de téléchargement au répertoire autorisé |

## 8. Perspectives et veille technologique

La veille doit suivre les composants effectivement utilisés : avis de sécurité et notes de version de Suricata, syslog-ng, Elasticsearch, Kibana, Apache et PHP. Les règles Suricata et les champs utilisés par Elastic Security doivent également être réévalués lorsque les logiciels ou les signatures évoluent.

Une mise à jour pourra être étudiée dans un instantané des VM : relever les versions, appliquer le changement, rejouer les cinq scénarios, contrôler les pipelines, les alertes, les visualisations et les courriels. Cette procédure permettrait de vérifier la compatibilité avant de retenir la nouvelle configuration.

La veille sur les techniques de reconnaissance, les injections et les contournements de signatures pourra servir à enrichir les tests. Toute nouvelle variante devra être accompagnée de sa commande, de ses événements et de ses résultats propres.

## 9. Conclusion

Le projet démontre une chaîne fonctionnelle de collecte, de détection, de visualisation et de notification pour les cinq scénarios retenus. Les preuves relient les événements aux alertes, puis les notifications indexées aux courriels reçus.

Le résultat est un laboratoire reproductible pour étudier ces comportements et comprendre leur détection. Les validations réalisées portent sur les essais documentés ; la couverture générale, la résistance aux pannes et les performances sous charge restent à évaluer.
