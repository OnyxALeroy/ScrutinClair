.PHONY: help init init-light up up-light down restart shell db-shell diff migrate db-reset cache import import-scrutins import-personnalites ai-up ai-down ai-status vulgarise vulgarise-dry vulgarise-limit vulgarise-status clean

# Variables pour éviter de répéter les noms
PHP = scrutinclair-php
DB = scrutinclair-database
DB_USER = app
DB_NAME = app
OLLAMA = scrutinclair-ollama
MODEL ?= qwen2.5:7b-instruct
N ?= 100
VULG_OPTS ?=

# Fichiers compose : le service Ollama n'existe que via le profil "ai"
COMPOSE = docker compose
COMPOSE_AI = docker compose -f docker-compose.yml -f compose.ai.yaml

# Affiche la liste des commandes disponibles par défaut
help:
	@grep -E '(^[a-zA-Z_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[32m%-20s\033[0m %s\n", $$1, $$2}'

## --- Docker ---
init: ## Initialise la stack (build + dépendances PHP)
	$(COMPOSE) --profile ai up -d --build
	$(COMPOSE) exec $(PHP) composer install

init-light: ## Initialise la stack SANS Ollama (dev léger, IA désactivée)
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
import: ## Lance tous les imports (scrutins, personnalites)
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-personnalites --no-debug
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-assemblee --no-debug

import-scrutins: ## Lance l'importation de l'Assemblée avec 1Go de RAM
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-assemblee --no-debug

import-personnalites: ## Lance l'importation des personnalités politiques avec 1Go de RAM
	docker compose exec $(PHP) php -d memory_limit=1G bin/console app:import-personnalites --no-debug

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
	docker compose exec $(PHP) bin/console app:vulgariser --status

## --- Utils ---
clean: ## Supprime les archives téléchargées et vide le cache Symfony (elles seront re-téléchargées)
	rm -rf data/
	docker compose exec scrutinclair-php bin/console cache:clear