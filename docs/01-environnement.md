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

- Ajouter la configuration Netplan réellement utilisée, pour documenter l’attribution persistante de l’adresse Ubuntu.
- Préciser la méthode d’attribution de l’adresse Kali.
- Ajouter les captures VirtualBox, les adresses des interfaces et le résultat du test de connectivité dans `captures/`, puis les intégrer à cette page avec leurs légendes.

La commande suivante permet de consulter la configuration Netplan existante sur Ubuntu :

```bash
sudo cat /etc/netplan/*.yaml
```

## Navigation

[Retour au README](../README.md) · [Installation d’Elasticsearch et de Kibana](02-installation-elasticsearch-kibana.md)
