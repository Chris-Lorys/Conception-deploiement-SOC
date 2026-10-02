# Visualisations Kibana

## 1. Rôle du Dashboard SOC

Le **Dashboard SOC** donne une vue synthétique des alertes du laboratoire : leur nombre, leur répartition par règle, leur évolution dans le temps et leurs adresses sources. Le tableau de documents permet ensuite d’examiner les alertes récentes.

Les trois captures présentent **cinq panneaux** du même dashboard, à différentes positions de défilement. Elles utilisent la période **Today**, correspondant au **1er octobre 2026** dans le fuseau d’affichage Kibana. Le filtre KQL global est vide. La carte et la répartition par scénario utilisent la vue **Alertes Elastic**, comme indiqué lors de la vérification de leur configuration.

Les preuves détaillées des scénarios et des notifications sont présentées dans le [guide d’utilisation](07-guide-utilisation.md). Le dashboard compte les alertes de la période sélectionnée ; il ne compte pas l’ensemble des journaux système et réseau.

## 2. Total et répartition des alertes

![Total et répartition des alertes du Dashboard SOC](../captures/dashboard/soc-indicateurs.png)

### Carte de synthèse

La carte intitulée **Total des événements de sécurité** affiche **Nombre d’enregistrements : 8**. Avec la vue **Alertes Elastic**, ces huit documents sont des alertes Elastic Security.

La mesure à utiliser pour ce total est **Nombre d’enregistrements**, sur la période du dashboard. Un filtre limité aux journaux SSH ou au processus Suricata ne convient pas pour compter toutes les alertes : certains types de règle ne renseignent pas ces champs.

### Répartition par scénario

Le graphique horizontal associe les noms de règles sur l’axe **Scénarios d’intrusion** à leur **Nombre d’enregistrements**.

| Règle visible | Nombre d’alertes |
| --- | --- |
| Tentative de traversée de répertoires | 4 |
| Tentative d’exploitation de Log4Shell - JNDI | 2 |
| Scan de ports potentiel — nombreux ports contactés | 1 |
| Tentative d’injection SQL | 1 |
| **Total** | **8** |

La somme concorde avec la carte. Les quatre alertes de traversée concernent plusieurs tentatives dans la journée ; elles ne sont pas attribuées à la seule tentative de 21:17.

Le scénario SSH documenté a été exécuté le 30 septembre, hors de la journée affichée. Son absence de ce graphique ne remet pas en cause sa détection. Le nombre d’alertes par catégorie mesure la fréquence des détections dans la sélection, et non leur gravité ou le nombre d’exploitations réussies.

## 3. Évolution temporelle et adresses sources

![Évolution des alertes et adresses IP sources](../captures/dashboard/soc-activite-sources.png)

### Évolution des événements dans le temps

L’axe horizontal représente les heures du **1er octobre 2026**. L’axe vertical représente le **Nombre d’enregistrements** par intervalle temporel.

Les cinq barres visibles ont les valeurs **1, 2, 1, 1 et 3**, soit **8** au total. Les premières détections se situent entre les graduations 06:00 et 12:00, puis après 12:00 ; d’autres apparaissent après 18:00. Pour lire les bornes exactes d’une barre, consulter son info-bulle.

Ce panneau permet de repérer une période de détection avant d’examiner les alertes correspondantes. L’intervalle d’agrégation du graphique ne définit pas la fréquence d’exécution des règles Elastic Security.

### Sources IP les plus actives

L’axe horizontal **Adresses IP sources** présente une seule catégorie : **192.168.56.101**, l’adresse de Kali. La barre atteint **8** sur l’axe **Nombre d’enregistrements**.

Les huit alertes de la sélection sont ainsi représentées sous la même source, en cohérence avec les tests réalisés depuis Kali. Ce panneau permet à l’administrateur d’identifier la source associée aux détections. Il compte ici les documents d’alerte, sans mesurer le volume total de trafic ou le nombre de paquets.

## 4. Alertes récentes

![Documents d’alerte récents](../captures/dashboard/soc-evenements-recents.png)

Le panneau **Événements récents** affiche **8 documents**, avec un tri décroissant sur `@timestamp`.

| Colonne | Utilité |
| --- | --- |
| @timestamp | Situer le document d’alerte |
| message | Lire le message d’origine conservé |
| source.ip | Identifier la source |
| event.action | Consulter l’action normalisée lorsqu’elle est renseignée |
| process.name | Identifier le processus présent dans le document |

La première ligne affiche **21:17:59.160**, avec **192.168.56.101** et **suricata**. Cet horodatage correspond à l’alerte de traversée documentée en section 7 du guide d’utilisation. Les lignes suivantes montrent notamment **21:09:57.548**, **21:00:53.200**, **20:59:53.196** et **13:06:47.900** : ce sont des documents distincts.

La date contenue dans `message` peut désigner l’événement IDS d’origine, tandis que `@timestamp` affiché situe le document d’alerte. Pour le test de 21:17, l’événement IDS est horodaté **21:17:39.468** et l’alerte Elastic Security **21:17:59.160**.

Une valeur `(null)` signifie que le champ n’est pas renseigné dans le document affiché. Tous les types d’alerte ne possèdent pas les mêmes champs.

## 5. Consultation du dashboard

1. Ouvrir **Kibana → Tableaux de bord → Dashboard SOC**.
2. Choisir une période couvrant les tests à analyser. Pour retrouver les données illustrées, sélectionner une période absolue couvrant le **1er octobre 2026** dans le fuseau d’affichage Kibana.
3. Actualiser les données et comparer le total, la répartition par règle, l’histogramme et les adresses sources.
4. Examiner les documents récents, puis ouvrir les alertes dans **Security → Détections → Alertes** pour lire leur nom de règle et leurs détails.
5. Comparer les alertes aux événements Discover et aux notifications du guide d’utilisation.

**Today** est une période mobile : sa signification change chaque jour. Les captures montrent le mode édition. Après une modification, utiliser **Enregistrer**, puis **Quitter l’édition**. L’export fourni ensuite conserve le dashboard enregistré et ses cinq panneaux.

## 6. Configuration confirmée par l’export

Le fichier [dashboard-soc.ndjson](../exports-kibana/dashboard-soc.ndjson) conserve la configuration exportée du dashboard et de ses dépendances.

Tous les panneaux actifs utilisent la vue **Alertes Elastic**, dont le modèle d’index est `.alerts-security.alerts-default` et le champ temporel `@timestamp`. La requête KQL globale est vide. Les visualisations Lens actives et la recherche enregistrée ne comportent pas de filtre supplémentaire.

| Panneau | Configuration exportée |
| --- | --- |
| Total | Métrique Lens ; comptage des enregistrements |
| Répartition par scénario | Barres horizontales ; comptage regroupé sur kibana.alert.rule.name ; neuf valeurs principales, tri décroissant par nombre ; catégorie « autres » activée |
| Évolution temporelle | Barres ; comptage par histogramme sur @timestamp ; intervalle automatique |
| Sources IP | Barres ; comptage regroupé sur source.ip ; neuf valeurs principales, tri décroissant par nombre ; catégorie « autres » activée |
| Alertes récentes | Recherche enregistrée ; colonnes @timestamp, message, source.ip, event.action, process.name ; tri @timestamp décroissant |

Les couches Lens actives prennent en compte les filtres globaux (`ignoreGlobalFilters: false`) et n’appliquent pas de réduction d’échantillonnage (`sampling: 1`).

Le dashboard contient **cinq panneaux**, mais l’export compte **six objets enregistrés** : un dashboard, trois visualisations Lens, une recherche et une vue de données. Le graphique de répartition par scénario est enregistré directement dans le dashboard et ne constitue pas un objet Lens séparé.

Le récapitulatif de l’export indique `exportedCount: 6`, `missingRefCount: 0` et une liste vide de références manquantes. Les références déclarées entre ces objets sont présentes dans le fichier.

## 7. Réimporter le dashboard

La [procédure de réimportation](../exports-kibana/README.md) indique les prérequis et les contrôles à effectuer. Le fichier conserve les objets Kibana ; il ne sauvegarde pas les journaux, les alertes Elasticsearch, les règles de détection ou les secrets du relais.

Le dashboard et la recherche ont `timeRestore: false` : sélectionner la période après l’import. Pour retrouver les données des captures, choisir le **1er octobre 2026 en UTC−4**. Les données correspondantes doivent encore être présentes dans Elasticsearch.

La vérification du fichier confirme sa structure et ses dépendances. Aucune réimportation dans une seconde instance n’est attestée à ce stade.
