# Visualisations Kibana

## 1. Rôle du Dashboard SOC

Le **Dashboard SOC** donne une vue synthétique des alertes du laboratoire : leur nombre, leur répartition par règle, leur évolution dans le temps et leurs adresses sources. Le tableau de documents permet ensuite d’examiner les alertes récentes.

Les trois captures présentent **cinq panneaux** du même dashboard, à différentes positions de défilement. Elles utilisent la période **Today**, correspondant au **1er octobre 2026** dans le fuseau d’affichage Kibana. Le filtre KQL global est vide. La carte et la répartition par scénario utilisent la vue **Alertes Elastic**, comme indiqué lors de la vérification de leur configuration.

Les preuves détaillées des scénarios et des notifications sont présentées dans le [guide d’utilisation](06-guide-utilisation.md). Le dashboard compte les alertes de la période sélectionnée ; il ne compte pas l’ensemble des journaux système et réseau.

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

**Today** est une période mobile : sa signification change chaque jour. Les captures montrent le mode édition. Après une modification, utiliser **Enregistrer**, puis **Quitter l’édition** ; les captures ne prouvent pas à elles seules que les changements ont été enregistrés.

## 6. Reproduire les visualisations

| Panneau | Construction |
| --- | --- |
| Total | Vue Alertes Elastic ; mesure Nombre d’enregistrements |
| Répartition par scénario | Comptage des alertes regroupées par nom de règle |
| Évolution temporelle | Comptage par histogramme temporel sur @timestamp |
| Sources IP | Comptage des alertes regroupées sur source.ip |
| Alertes récentes | Tableau avec les colonnes de la section 4 ; tri @timestamp décroissant |

Pour conserver une sélection cohérente, utiliser la même source d’alertes et la même période sur les panneaux concernés. Relever dans chaque éditeur les filtres propres au panneau, les champs exacts de regroupement et l’intervalle de l’histogramme. Ces paramètres internes ne sont pas tous visibles dans les captures.

Un export du dashboard avec ses dépendances dans `exports-kibana/` permettra de conserver sa configuration exacte. Les captures documentent sa lecture : **8 alertes**, réparties **4 + 2 + 1 + 1**, représentées dans le temps, associées à Kali et accessibles dans le tableau.

