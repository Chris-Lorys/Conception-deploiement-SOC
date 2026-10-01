#!/usr/bin/env python3
import json
import smtplib
import sqlite3
import ssl
import urllib.request
from email.message import EmailMessage
from pathlib import Path

CONFIG = "/etc/soc-notifications.json"
CERTIFICAT = "/etc/elasticsearch/certs/http_ca.crt"
ETAT = Path("/var/lib/soc-notifications/etat.db")

def expliquer(scenario, regle):
    nom = (scenario + " " + regle).lower()

    if "ssh" in nom:
        return (
            "Plusieurs échecs de connexion SSH ont été détectés. Ils peuvent "
            "correspondre à une tentative de deviner un mot de passe. "
            "L'alerte ne signifie pas qu'un accès a été obtenu."
        )
    if "nmap" in nom or "scan" in nom:
        return (
            "Un balayage réseau a été détecté. Il permet d'identifier les "
            "ports et services accessibles et peut précéder une intrusion. "
            "Le scan seul ne prouve pas qu'un service a été compromis."
        )
    if "log4" in nom or "jndi" in nom:
        return (
            "Une requête contenant un motif JNDI associé à Log4Shell a été "
            "détectée. Sur une application vulnérable, elle pourrait conduire "
            "à l'exécution de code. La détection ne prouve pas son exploitation."
        )
    if "sql" in nom:
        return (
            "Une tentative d'injection SQL a été détectée sur le formulaire "
            "de connexion. Elle pourrait modifier la requête d'authentification "
            "et permettre un accès non autorisé. La réussite n'est pas établie."
        )
    if "traver" in nom or "path" in nom:
        return (
            "Une tentative de traversée de répertoires a été détectée. Elle "
            "pourrait permettre de lire des fichiers hors du répertoire prévu. "
            "La détection ne prouve pas qu'un fichier a été consulté."
        )
    return "Une activité suspecte a été détectée. Consultez l'alerte dans Kibana."

with open(CONFIG, encoding="utf-8") as fichier:
    config = json.load(fichier)

requete = urllib.request.Request(
    "https://localhost:9200/lab-notifications/_search",
    data=json.dumps({
        "size": 1000,
        "sort": [{"@timestamp": {"order": "desc"}}]
    }).encode(),
    headers={
        "Authorization": "ApiKey " + config["cle_elastic"],
        "Content-Type": "application/json"
    },
    method="POST"
)

contexte = ssl.create_default_context(cafile=CERTIFICAT)
with urllib.request.urlopen(requete, context=contexte, timeout=10) as reponse:
    documents = json.load(reponse)["hits"]["hits"]

ETAT.parent.mkdir(mode=0o700, exist_ok=True)
with sqlite3.connect(ETAT) as base:
    base.execute("CREATE TABLE IF NOT EXISTS envoyees (id TEXT PRIMARY KEY)")
    nouvelles = [
        doc for doc in reversed(documents)
        if not base.execute(
            "SELECT 1 FROM envoyees WHERE id = ?", (doc["_id"],)
        ).fetchone()
    ]

    if not nouvelles:
        print("Aucune nouvelle notification.")
    else:
        with smtplib.SMTP_SSL(
            "smtp.gmail.com", 465,
            context=ssl.create_default_context(), timeout=15
        ) as smtp:
            smtp.login(config["expediteur"], config["mot_de_passe_gmail"])

            for doc in nouvelles:
                alerte = doc["_source"]
                scenario = alerte.get("scenario", "Non précisé")
                regle = alerte.get("rule_name", "Alerte de sécurité").strip()

                mail = EmailMessage()
                mail["From"] = f'Ne pas répondre - Alertes SOC <{config["expediteur"]}>'
                mail["To"] = config["destinataire"]
                mail["Subject"] = f"Alerte SOC — {regle}"
                mail.set_content(
                    "Bonjour,\n\n"
                    "Une alerte de sécurité a été générée dans le laboratoire.\n\n"
                    f"Scénario : {scenario}\n"
                    f"Règle : {regle}\n"
                    f"Date : {alerte.get('@timestamp', 'Non précisée')}\n"
                    f"Détection : {alerte.get('message', 'Non précisée')}\n\n"
                    f"Explication : {expliquer(scenario, regle)}\n\n"
                    f"Identifiant de l'alerte : "
                    f"{alerte.get('alert_id', 'Non précisé')}\n"
                )

                smtp.send_message(mail)
                base.execute(
                    "INSERT INTO envoyees (id) VALUES (?)", (doc["_id"],)
                )
                base.commit()
                print("Courriel envoyé pour :", regle)
