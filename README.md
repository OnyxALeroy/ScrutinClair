# ScrutinClair

La vie parlementaire expliquée simplement : lecture des scrutins de l'Assemblée
nationale, résultat des votes par groupe politique et — quand la génération IA est
activée — résumé « en clair » de chaque texte de loi.

## Démarrage

```bash
make init        # build des images + dépendances PHP + démarrage (avec Ollama)
make init-light  # idem sans Ollama (dev léger)
make up          # démarre la stack (base, PHP, nginx, Ollama)
```

L'application est alors disponible sur <http://localhost:8000>.

## Permissions des fichiers

Le conteneur PHP tourne avec l'`uid`/`gid` de l'utilisateur hôte : le `Makefile` les
exporte (`UID`/`GID`) et `docker-compose.yml` les applique via `user:`. Les fichiers
générés dans le répertoire du projet — `data/` (archives open data), `var/` (cache,
logs), `vendor/` (dépendances) — appartiennent donc à l'utilisateur et se suppriment
sans `sudo` :

```bash
rm -rf data/     # OK, aucune erreur de permission
```

Passe par `make` (ou exporte `UID`/`GID`) pour que l'uid corresponde à l'utilisateur
courant. Pour purger des fichiers créés par une ancienne version de la stack (donc
encore appartenant à `root`) :

```bash
docker compose exec -T -u root scrutinclair-php sh -c 'rm -rf /var/www/symfony/data'
```

## Utilitaires

| Cible | Effet |
| --- | --- |
| `make clean` | Supprime les archives open data téléchargées (`data/`) et vide le cache Symfony. Les archives seront retéléchargées au prochain import. |
| `make cache` | Vide le cache Symfony. |
| `make shell` | Ouvre un terminal dans le conteneur PHP. |
| `make db-shell` | Ouvre la console `psql` dans le conteneur Postgres. |
| `make migrate` / `make diff` | Applique les migrations Doctrine / en génère une nouvelle. |
| `make db-reset` | Supprime la base, la recrée et rejoue les migrations. |
| `make restart` | Redémarre les conteneurs. |

## IA / vulgarisation

Les résumés « en clair » sont produits par [Ollama](https://ollama.com/) (modèle
`qwen2.5:7b-instruct` par défaut), lancé dans un conteneur dédié. Ce conteneur est
lourd : il n'est construit et démarré que si le profil Docker `ai` est activé.

| Cible | Effet |
| --- | --- |
| `make up` | Stack complète : Ollama démarre et l'IA est activée. |
| `make up-light` | **Mode dev léger** : ni build ni lancement d'Ollama, et l'IA est désactivée dans l'application. |
| `make ai-up` | Démarre Ollama (avec réservation GPU NVIDIA), télécharge le modèle et active l'IA. |
| `make ai-down` | Arrête Ollama et repasse l'application en mode sans IA. |
| `make ai-status` | Liste les modèles Ollama chargés. |
| `make vulgarise` | Lance la génération des résumés (`bin/console app:vulgariser`). |

### Le drapeau `AI_ENABLED`

C'est lui qui décide si l'application a le droit de générer — et d'annoncer — des
résumés par IA :

- `AI_ENABLED=1` (défaut dans `.env`) : l'IA est autorisée. Un texte sans résumé
  affiche « pas encore vulgarisé » ; un résumé déjà généré est affiché tel quel.
- `AI_ENABLED=0` : l'IA est désactivée. Un texte sans résumé affiche « aucun rapport
  ne peut être généré » ; les résumés déjà en base restent visibles, accompagnés d'une
  mention indiquant que plus aucun nouveau résumé ne sera produit.

En Docker, c'est la cible `make` utilisée qui fait foi (`make up-light` force
`AI_ENABLED=0` dans le conteneur PHP). Hors Docker, on positionne la variable dans
`.env.local` :

```dotenv
AI_ENABLED=0
```

### Génération des résumés

```bash
make vulgarise-dry      # résout les sources sans appeler l'IA (mesure la couverture)
make vulgarise          # génération complète (reprise automatique)
make vulgarise-limit N=200
make vulgarise-status   # compteurs d'avancement
```

La commande `app:vulgariser` refuse de s'exécuter si `AI_ENABLED=0` : aucun rapport ne
peut alors être produit.
