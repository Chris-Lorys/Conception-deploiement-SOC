# Configuration de l’environnement

## Objectif

Deux machines virtuelles VirtualBox constituent le laboratoire : Ubuntu héberge la collecte, la détection et la visualisation ; Kali génère le trafic des scénarios. L’ordinateur hôte utilise Windows 11 et dispose de 16 Go de RAM.

## Machines et adresses

| Machine / interface | Système ou réseau | Adresse | Fonction |
|---|---|---|---|
| Ubuntu — enp0s8 | Ubuntu Server 24.04.4 LTS, Host-Only | 192.168.56.10/24 | Services du laboratoire et trafic surveillé |
| Ubuntu — enp0s3 | NAT | 10.0.2.15/24 | Téléchargement des paquets et des règles |
| Kali | Kali Linux 2025.4, Host-Only | 192.168.56.101/24 | Exécution des tests |
| Hôte Windows | Host-Only | 192.168.56.1/24 | Accès au réseau des VM |

Le serveur Ubuntu dispose d’environ 7,8 Gio de mémoire utilisable et d’un volume logique système d’environ 29 Go.

## Paramétrage VirtualBox

1. Ouvrir les paramètres réseau du serveur Ubuntu.
2. Configurer l’adaptateur 1 en NAT et l’adaptateur 2 en réseau privé hôte (Host-Only).
3. Sélectionner le réseau Host-Only correspondant au sous-réseau `192.168.56.0/24` et activer le câble virtuel.
4. Connecter Kali au même réseau Host-Only.
5. Vérifier les adresses dans les systèmes invités avant de lancer les tests.

Le réseau Host-Only permet les échanges entre les VM et l’hôte. L’interface NAT d’Ubuntu permet l’accès à Internet.

## Configuration persistante avec Netplan

La capture du serveur montre la configuration suivante, obtenue avec `sudo cat /etc/netplan/*.yaml` :

```yaml
network:
  version: 2
  ethernets:
    enp0s3:
      dhcp4: true
    enp0s8:
      dhcp4: false
      addresses:
        - 192.168.56.10/24
```

| Paramètre | Explication |
|---|---|
| `version: 2` | Version du format de configuration Netplan |
| `enp0s3: dhcp4: true` | L’interface NAT reçoit automatiquement sa configuration IPv4 par DHCP |
| `enp0s8: dhcp4: false` | Désactive l’attribution IPv4 par DHCP sur l’interface Host-Only |
| `192.168.56.10/24` | Fixe l’adresse du serveur sur le réseau du laboratoire, avec le masque `255.255.255.0` |

L’adresse fixe permet à Kali et aux services du laboratoire de retrouver le serveur à la même adresse. Aucune passerelle par défaut n’est définie sur l’interface Host-Only ; l’accès à Internet utilise l’interface NAT.

### Reproduire cette configuration

Lister les fichiers existants pour identifier celui à modifier :

```bash
ls -l /etc/netplan/
```

Modifier le fichier YAML qui définit ces interfaces avec `sudo nano /etc/netplan/NOM_DU_FICHIER.yaml`, en remplaçant `NOM_DU_FICHIER.yaml` par son nom réel. La capture ne montre pas ce nom. Reporter la configuration ci-dessus en respectant l’indentation et éviter de définir les mêmes interfaces dans plusieurs fichiers.

Depuis la console VirtualBox du serveur, valider puis appliquer la configuration :

```bash
sudo netplan generate
sudo netplan try
```

`generate` vérifie et génère la configuration réseau. `try` l’applique temporairement et demande une confirmation ; sans confirmation, les modifications sont annulées. Vérifier la connectivité avant de confirmer.

Les commandes de cette section servent à reproduire le paramétrage ; le serveur déjà configuré n’a pas besoin d’être modifié.

## Contrôle des interfaces

Sur chacune des VM :

```bash
ip -br addr
```

La sortie présente l’état et les adresses de chaque interface. Sur Ubuntu, vérifier `192.168.56.10/24` sur `enp0s8` ; sur Kali, vérifier `192.168.56.101/24` sur l’interface Host-Only.

Sur Ubuntu :

```bash
ip route
```

La route vers `192.168.56.0/24` doit utiliser `enp0s8`. La route par défaut doit utiliser l’interface NAT.

## Test de connectivité

Depuis Kali :

```bash
ping -c 4 192.168.56.10
```

Depuis Ubuntu :

```bash
ping -c 4 192.168.56.101
```

Chaque commande envoie quatre requêtes ICMP. Les réponses permettent de vérifier la connectivité dans les deux sens. Ces commandes sont des contrôles à reproduire ; les captures du laboratoire doivent confirmer les résultats observés.

## Contrôle des ressources

Sur Ubuntu :

```bash
free -h
df -h /
```

`free -h` affiche la mémoire totale, utilisée et disponible. `df -h /` affiche l’espace du système de fichiers principal. Ces informations permettent de vérifier les ressources avant le démarrage des services.

## Éléments à compléter pour la reproductibilité

- Préciser la méthode d’attribution de l’adresse Kali.
- Ajouter les captures VirtualBox, les adresses des interfaces et le résultat du test de connectivité dans `captures/`, puis les intégrer à cette page avec leurs légendes.

La commande suivante permet de consulter la configuration Netplan existante sur Ubuntu :

```bash
sudo cat /etc/netplan/*.yaml
```

## Navigation

[Retour au README](../README.md) · [Installation d’Elasticsearch et de Kibana](02-installation-elasticsearch-kibana.md)
