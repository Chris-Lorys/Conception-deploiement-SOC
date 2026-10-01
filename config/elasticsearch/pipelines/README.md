# Recréer les pipelines d’ingestion

Ces corps JSON reproduisent les pipelines confirmés dans le laboratoire. Ils ne contiennent ni l’enveloppe de réponse GET ni les dates de métadonnées.

Dans Kibana → Dev Tools, sur un déploiement à reproduire :

1. Exécuter `PUT _ingest/pipeline/ssh-auth` avec le contenu de [ssh-auth.json](ssh-auth.json).
2. Exécuter `PUT _ingest/pipeline/system-logs` avec le contenu de [system-logs.json](system-logs.json).
3. Exécuter `PUT _ingest/pipeline/suricata-json` avec le contenu de [suricata-json.json](suricata-json.json).

Chaque réponse attendue contient `"acknowledged": true`. Ces requêtes créent ou remplacent les pipelines ; elles ne sont pas nécessaires sur le laboratoire déjà fonctionnel.

## Vérification en lecture seule

```http
GET _ingest/pipeline/ssh-auth
GET _ingest/pipeline/system-logs
GET _ingest/pipeline/suricata-json
```

## Test sans indexation

Pour vérifier le traitement SSH :

```http
POST _ingest/pipeline/system-logs/_simulate
{
  "docs": [
    {
      "_source": {
        "@timestamp": "2026-09-30T21:15:04Z",
        "host": {"name": "server"},
        "process": {"name": "sshd"},
        "message": "Failed password for invalid user labtest from 192.168.56.101 port 45678 ssh2"
      }
    }
  ]
}
```

Le document résultat doit contenir `user.name: labtest`, `source.ip: 192.168.56.101`, `source.port: 45678`, `event.outcome: failure` et `event.action: ssh_login_failed`. Cette simulation propose une vérification reproductible ; son exécution n’est pas attestée par les captures fournies.

Le grok SSH n’a pas de gestion d’échec explicite : un message commençant par Failed password for mais ne correspondant pas au motif peut faire échouer le traitement.

[Guide syslog-ng](../../../docs/03-installation-syslog-ng.md)
