# Export du Dashboard SOC

## Fichier et contenu

[dashboard-soc.ndjson](dashboard-soc.ndjson) est l’export fourni depuis Kibana pour le **Dashboard SOC** du laboratoire. Son contenu est conservé sans modification.

| Type d’objet | Nombre | Contenu |
| --- | --- | --- |
| dashboard | 1 | Dashboard SOC, avec cinq panneaux |
| lens | 3 | Total, évolution temporelle, adresses IP sources |
| search | 1 | Événements récents |
| index-pattern | 1 | Vue Alertes Elastic, index .alerts-security.alerts-default |
| **Total** | **6** | Le graphique par scénario est intégré au dashboard |

Le récapitulatif indique **zéro référence manquante**. Les références déclarées entre les objets exportés sont présentes. La lecture détaillée des panneaux figure dans le [guide des visualisations](../docs/08-visualisations-kibana.md).

Cet export contient des configurations Kibana, sans les documents Elasticsearch, les règles de détection ni la configuration SMTP. Il ne remplace pas une sauvegarde des données.

## Prérequis

- Elasticsearch et Kibana accessibles, avec un compte autorisé à gérer les objets enregistrés et à consulter les alertes.
- Pour reproduire l’environnement documenté, utiliser Kibana **9.5.4**, la version relevée dans le laboratoire.
- Disposer des alertes dans `.alerts-security.alerts-default` pour obtenir des graphiques renseignés.

Les champs `coreMigrationVersion` et `typeMigrationVersion` du fichier sont des métadonnées de migration des objets ; ils ne constituent pas un relevé de la version du serveur.

Selon la [documentation Elastic](https://www.elastic.co/docs/extend/kibana/key-concepts/saved-objects/export), l’import est compatible avec la même version, une version mineure plus récente de la même version majeure ou la version majeure suivante. L’import vers une version plus ancienne n’est pas pris en charge. Conserver le fichier exporté intact.

## Import depuis l’interface

1. Télécharger **dashboard-soc.ndjson** depuis ce dossier.
2. Ouvrir Kibana dans l’espace où le dashboard doit être disponible.
3. Ouvrir **Gestion de la pile → Objets enregistrés**.
4. Cliquer sur **Importer** et sélectionner le fichier.
5. Examiner les éventuels conflits. Si le dashboard existe déjà, choisir son remplacement uniquement pour restaurer la configuration exportée ; conserver l’objet existant si ses modifications doivent être gardées.
6. Vérifier le résultat d’import et l’absence d’erreur ou de référence manquante.
7. Ouvrir **Tableaux de bord → Dashboard SOC**.

## Contrôles après import

- Vérifier la présence des **cinq panneaux**.
- Vérifier la vue **Alertes Elastic** et son champ temporel `@timestamp`.
- Choisir une période contenant des alertes. Pour comparer avec les captures, utiliser le **1er octobre 2026 en UTC−4**, si ces données sont toujours disponibles.
- Comparer le total, la répartition et l’histogramme ; consulter les documents récents.
- Pour retrouver aussi le scénario SSH documenté le 30 septembre, élargir la période.
- En cas de panneau vide, vérifier la période, la présence des données, les permissions et la vue de données.

Le dashboard ne restaure pas une période enregistrée (`timeRestore: false`). Les **huit alertes** des captures sont un résultat historique, pas une valeur incluse dans l’export.

## État de validation

Le fichier a été analysé : lignes JSON valides, six objets enregistrés, cinq panneaux et références déclarées présentes. La réimportation dans une autre instance Kibana n’a pas encore été testée.
