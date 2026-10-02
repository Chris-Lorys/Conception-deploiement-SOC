# Configuration du Dashboard SOC

[dashboard-soc.ndjson](dashboard-soc.ndjson) contient le dashboard, ses visualisations, sa recherche enregistrée et la vue **Alertes Elastic**. Les cinq panneaux sont décrits dans le [guide des visualisations](../docs/08-visualisations-kibana.md).

## Import dans Kibana

1. Ouvrir **Gestion de la pile → Objets enregistrés** dans l’espace de destination.
2. Cliquer sur **Importer** et sélectionner `dashboard-soc.ndjson`.
3. Examiner les conflits éventuels avant de remplacer un objet existant.
4. Ouvrir **Dashboard SOC** et choisir une période contenant des alertes.

Les données doivent être présentes dans `.alerts-security.alerts-default`. L’export contient la configuration des panneaux, sans les journaux, les alertes ou les règles de détection. Pour retrouver les résultats des captures, choisir le **1er octobre 2026 en UTC−4**.

Utiliser une version Kibana compatible avec celle de l’export. Les règles de compatibilité sont précisées dans la [documentation Elastic](https://www.elastic.co/docs/extend/kibana/key-concepts/saved-objects/export). Le fichier est conservé tel qu’exporté ; sa réimportation n’a pas été testée.
