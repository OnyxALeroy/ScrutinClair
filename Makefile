.PHONY: help init init-light up up-light down restart shell db-shell diff migrate db-reset cache import import-scrutins import-personnalites import-votes import-senat backfill-textes ai-up ai-down ai-status ia-traiter ia-status vulgarise vulgarise-dry vulgarise-limit vulgarise-status vulgarise-full vulgarise-stop test clean

# Variables pour éviter de répéter les noms
PHP = php
DB = database
DB_USER = app
DB_NAME = app
OLLAMA = ollama
# Volumes externes : créés une fois, jamais supprimés par Compose (le nom est
# figé, un changement de configuration ne peut donc pas vider la base).
VOLUME_DB = scrutinclair_db-data
VOLUME_OLLAMA = scrutinclair_ollama-models
MODEL ?= qwen2.5:7b-instruct
N ?= 100
VULG_OPTS ?=

# Fichiers compose : le service Ollama n'existe que via le profil "ai"
COMPOSE = docker compose
COMPOSE_AI = docker compose -f docker-compose.yml -f compose.ai.yaml

# uid/gid de l'utilisateur hôte : passés au conteneur PHP (docker-compose.yml)
# pour qu'il écrive les fichiers du bind-mount avec les mêmes permissions que
# l'hôte (data/, var/, vendor/ restent supprimables sans sudo).
export UID := $(shell id -u)
export GID := $(shell id -g)

# Affiche la liste des commandes disponibles par défaut
help:
	@grep -E '(^[a-zA-Z_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[32m%-20s\033[0m %s\n", $$1, $$2}'

## --- Docker ---
init: ## Initialise la stack (build + dépendances PHP)
	docker volume create $(VOLUME_DB)
	docker volume create $(VOLUME_OLLAMA)
	$(COMPOSE) --profile ai up -d --build
	$(COMPOSE) exec $(PHP) composer install

init-light: ## Initialise la stack SANS Ollama (dev léger, IA désactivée)
	docker volume create $(VOLUME_DB)
	$(COMPOSE) up -d --build
	$(COMPOSE) exec $(PHP) composer install
	AI_ENABLED=0 $(COMPOSE) up -d

up: ## Démarre les conteneurs en arrière-plan (IA activée)
	$(COMPOSE) --profile ai up -d

up-light: ## Démarre la stack SANS Ollama (dev léger : aucun rapport IA ne peut être généré)
	$(COMPOSE) --profile ai stop $(OLLAMA) || true
	AI_ENABLED=0 $(COMPOSE) up -d

down: ## Arrête et supprime les conteneurs
	$(COMPOSE) --profile ai down

restart: ## Redémarre les conteneurs (base, PHP, nginx, Ollama)
	$(COMPOSE) --profile ai restart

shell: ## Ouvre un terminal bash dans le conteneur PHP
	docker compose exec $(PHP) sh

## --- Base de données (Postgres) ---
db-shell: ## Ouvre la console psql dans le conteneur Postgres
	docker compose exec $(DB) psql -U $(DB_USER) -d $(DB_NAME)

diff: ## Calcule la différence entre le code PHP et la BDD (génère une migration)
	docker compose exec $(PHP) bin/console make:migration

migrate: ## Applique les migrations SQL en attente
	docker compose exec $(PHP) bin/console doctrine:migrations:migrate --no-interaction

db-reset: ## Supprime la BDD, la recrée et relance les migrations
	docker compose exec $(PHP) bin/console doctrine:database:drop --force --if-exists
	docker compose exec $(PHP) bin/console doctrine:database:create
	docker compose exec $(PHP) bin/console doctrine:migrations:migrate --no-interaction

## --- Symfony ---
cache: ## Vide le cache de Symfony
	docker compose exec $(PHP) bin/console cache:clear

## --- App ---
import: ## Lance tous les imports (scrutins, personnalites, votes)
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-personnalites --no-debug
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-assemblee --no-debug
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-votes --no-debug

import-scrutins: ## Lance l'importation de l'Assemblée avec 1Go de RAM
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-assemblee --no-debug

import-personnalites: ## Lance l'importation des personnalités politiques avec 1Go de RAM
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-personnalites --no-debug

import-votes: ## Recrée les votes depuis les JSON sans toucher aux données IA (--no-debug évite l'accumulation SQL en mémoire)
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-votes --no-debug

import-senat: ## Importe les sénateurs, groupes et scrutins du Sénat (1Go de RAM)
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-senat --no-debug

backfill-textes: ## Complète les métadonnées des textes AN depuis les JSON locaux
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:backfill-textes-an --no-debug

## --- IA / Vulgarisation ---
ai-up: ## Démarre Ollama (GPU), récupère le modèle et active l'IA dans l'application
	AI_ENABLED=1 $(COMPOSE_AI) --profile ai up -d
	$(COMPOSE_AI) --profile ai exec $(OLLAMA) ollama pull $(MODEL)

ai-down: ## Arrête Ollama et repasse l'application en mode sans IA
	$(COMPOSE) --profile ai stop $(OLLAMA) || true
	AI_ENABLED=0 $(COMPOSE) up -d

ai-status: ## Liste les modèles Ollama chargés
	$(COMPOSE) --profile ai exec $(OLLAMA) ollama list

vulgarise-dry: ## Résout les sources SANS appeler l'IA (mesure la couverture)
	docker compose exec $(PHP) php -d memory_limit=2G bin/console app:vulgariser --dry-run --no-debug

vulgarise: ## Lance la vulgarisation complète (reprise automatique)
	docker compose exec $(PHP) php -d memory_limit=2G bin/console app:vulgariser $(VULG_OPTS) --no-debug

vulgarise-limit: ## Vulgarise N textes seulement (ex. make vulgarise-limit N=200)
	docker compose exec $(PHP) php -d memory_limit=2G bin/console app:vulgariser --limit=$(N) --no-debug

vulgarise-status: ## Affiche l'avancement de la vulgarisation (compteurs par statut)
	docker compose exec $(PHP) php -d memory_limit=2G bin/console app:vulgariser --status

vulgarise-full: ## Lance la vulgarisation complète en 2 processus supervisés (reprise auto)
	docker compose exec -d $(PHP) sh -c 'sh /var/www/symfony/docker/vulgarisation-supervise.sh 0/2'
	docker compose exec -d $(PHP) sh -c 'sh /var/www/symfony/docker/vulgarisation-supervise.sh 1/2'
	@echo "Vulgarisation lancée : logs dans var/log/vulgarisation-{0-2,1-2}.log"

vulgarise-stop: ## Arrête les processus de vulgarisation supervisés
	docker compose exec $(PHP) sh -c 'pkill -f "vulgarisation-super[v]ise"; pkill -f "app:vulgaris[e]r"' || true

ia-traiter: ## Traite titre court + tags + résumé texte par texte (ex. make ia-traiter 100 ou make ia-traiter LIMIT=100)
	@ARGS=""; \
	if [ -n "$(LIMIT)" ]; then ARGS="--limit=$(LIMIT)"; fi; \
	for arg in $(filter-out $@,$(MAKECMDGOALS)); do \
		if echo "$$arg" | grep -Eq '^[0-9]+$$'; then ARGS="--limit=$$arg"; fi; \
	done; \
	docker compose exec $(PHP) php -d memory_limit=2G bin/console app:ia:traiter $$ARGS --no-debug

ia-status: ## Affiche l'avancement du traitement IA (titre court, tags, résumé)
	docker compose exec $(PHP) php -d memory_limit=2G bin/console app:ia:traiter --status

%:
	@:

## --- Tests ---
test: ## Migre la base de test puis lance la suite PHPUnit
	docker compose exec $(PHP) php -d memory_limit=1G bin/console doctrine:migrations:migrate --env=test --no-interaction
	docker compose exec $(PHP) vendor/bin/phpunit


## --- Utils ---
clean: ## Supprime les archives téléchargées (data/) et vide le cache Symfony (elles seront re-téléchargées)
	$(COMPOSE) exec -T -u root $(PHP) sh -c 'rm -rf /var/www/symfony/data' || rm -rf data/
	-$(COMPOSE) exec -T $(PHP) bin/console cache:clear
