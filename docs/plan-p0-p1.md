# ScrutinClair : sécurisation de l'admin et nettoyage technique (P0 + P1)

> Ce document décrit **ce qui va être modifié, pourquoi, et comment le tester**.
> Choix validés : authentification **in-memory + form_login**, **suppression** du code Messenger mort.
> Toutes les commandes s'exécutent dans le conteneur PHP : `docker compose exec php …` (ou `make shell`).

---

## Sommaire

| # | Prio | Sujet | Risque traité |
|---|------|-------|---------------|
| 1 | P0 | Authentification de l'admin | N'importe qui peut lancer, annuler ou purger des imports et lire les logs |
| 2 | P0 | Protection CSRF des actions admin | Un site tiers peut déclencher des actions au nom d'un admin connecté |
| 3 | P0 | `symfony/process` en dépendance de prod | L'admin plante avec `composer install --no-dev` |
| 4 | P0 | Secrets et configuration de prod | `APP_SECRET` vide, l'application tourne en `dev` |
| 5 | P1 | Suppression du code Messenger mort | Du code jamais exécuté qui induit en erreur |
| 6 | P1 | Préparation des jobs centralisée | Logique du fichier de log copiée en 3 endroits |
| 7 | P1 | DTO + `#[MapQueryString]` / `#[MapRequestPayload]` | Lecture de la requête à la main, aucune validation |
| 8 | P1 | Monolog | Pas de fichier `var/log/*.log` |
| 9 | P1 | php-cs-fixer + CI GitHub Actions | Aucun contrôle automatique |

---

## 1. Authentification de l'admin (P0)

### Constat
`src/Controller/AdminImportController.php` expose `/admin/imports`, `/start`, `/clear`, `/{id}/cancel`, `/{id}/status`, `/{id}/stream`, `/stream-all` et `/{id}/download` **sans aucune authentification**. SecurityBundle n'est pas installé.

### Ce qui sera fait
1. `composer require symfony/security-bundle` : la recette Flex enregistre le bundle et crée `config/packages/security.yaml` et `config/routes/security.yaml`.
2. Configuration de `config/packages/security.yaml` :
   ```yaml
   security:
       password_hashers:
           Symfony\Component\Security\Core\User\InMemoryUser: 'auto'
       providers:
           admin_in_memory:
               memory:
                   users:
                       admin: { password: '%env(ADMIN_PASSWORD_HASH)%', roles: ['ROLE_ADMIN'] }
       firewalls:
           dev: { pattern: ^/(_profiler|_wdt|assets|build)/, security: false }
           main:
               lazy: true
               provider: admin_in_memory
               form_login:
                   login_path: app_login
                   check_path: app_login
                   enable_csrf: true
                   default_target_path: admin_imports
               logout:
                   path: app_logout
                   target: app_home
       access_control:
           - { path: ^/admin, roles: ROLE_ADMIN }

   when@test:
       security:
           password_hashers:
               Symfony\Component\Security\Core\User\InMemoryUser:
                   algorithm: auto
                   cost: 4
                   time_cost: 3
                   memory_cost: 10
   ```
   Les pages publiques restent accessibles : le firewall est `lazy` et seul `^/admin` est protégé.
3. Dans `.env`, ajouter une valeur par défaut **vide**, ce qui interdit toute connexion tant qu'aucun hash n'est configuré :
   ```dotenv
   # Hash du mot de passe admin : bin/console security:hash-password
   # À définir dans .env.local (entre guillemets simples, le hash contient des $) ou dans le vault.
   ADMIN_PASSWORD_HASH=
   ```
4. Nouveau `src/Controller/SecurityController.php` (étend `AbstractController`) :
   - `#[Route('/login', name: 'app_login')]` : affiche le formulaire et la dernière erreur via `AuthenticationUtils` ;
   - `#[Route('/logout', name: 'app_logout')]` : action vide, interceptée par le firewall.
5. Nouveau `templates/security/login.html.twig` : il étend `base.html.twig` et reprend le style existant. Champs `_username`, `_password`, et `_csrf_token` avec la valeur `csrf_token('authenticate')`.
6. `#[IsGranted('ROLE_ADMIN')]` sur la classe `AdminImportController`, en défense en profondeur avec `access_control`.
7. Un lien « Se déconnecter » (`path('app_logout')`) dans `templates/admin/imports/index.html.twig`.

### Mise en place locale (une fois)
```bash
docker compose exec php bin/console security:hash-password 'monMotDePasse'
# copier le hash dans .env.local :
# ADMIN_PASSWORD_HASH='$2y$13$....'
```

---

## 2. Protection CSRF des actions admin (P0)

### Constat
Les trois formulaires POST de `templates/admin/imports/index.html.twig` (lignes ~16, ~103 et ~171) n'ont pas de jeton CSRF. Une fois l'authentification en place, un site malveillant pourrait faire soumettre ces formulaires par le navigateur d'un admin connecté.

### Ce qui sera fait
- Si `symfony/security-csrf` n'est pas apporté par le bundle : `composer require symfony/security-csrf`. Vérifier ensuite `bin/console debug:config framework csrf_protection`.
- `#[IsCsrfTokenValid('admin-imports')]` (attribut natif, qui lit le champ `_token`) sur `start()`, `clear()` et `cancel()`.
- Dans chacun des 3 formulaires :
  ```twig
  <input type="hidden" name="_token" value="{{ csrf_token('admin-imports') }}">
  ```
- Un jeton invalide ou absent provoque un refus (403) et **aucun job n'est créé**.

---

## 3. `symfony/process` en dépendance de prod (P0)

### Constat
`ImportManager::start()` utilise `Symfony\Component\Process\Process`. Or ce paquet n'est installé que comme dépendance de `maker-bundle` (dans `packages-dev` de `composer.lock`), donc il disparaît avec `--no-dev`.

### Ce qui sera fait
- `composer require symfony/process`.

---

## 4. Secrets et configuration de prod (P0)

### Constat
`.env` contient `APP_ENV=dev` et `APP_SECRET=` (vide), et il n'existe aucune procédure de démarrage en prod.

### Ce qui sera fait
- `.env` **reste un fichier de valeurs par défaut** : il est versionné et ne contient aucun secret.
- Vault Symfony pour la prod :
  ```bash
  bin/console secrets:generate-keys --env=prod
  bin/console secrets:set APP_SECRET --env=prod --random
  bin/console secrets:set ADMIN_PASSWORD_HASH --env=prod   # coller le hash
  ```
  La clé publique et les secrets chiffrés sont versionnés. La clé privée `config/secrets/prod/prod.decrypt.private.php` **ne l'est pas** (vérifier `.gitignore`) : elle doit être copiée sur le serveur ou passée via `SYMFONY_DECRYPTION_SECRET`.
- Nouveau `compose.prod.yaml` (surcharge) : `APP_ENV=prod` et `APP_DEBUG=0` sur le service `php`.
- Nouvelle cible `make up-prod` :
  ```make
  up-prod:
  	docker compose -f docker-compose.yml -f compose.prod.yaml up -d
  	docker compose exec php composer install --no-dev --optimize-autoloader
  	docker compose exec php bin/console doctrine:migrations:migrate -n
  	docker compose exec php bin/console cache:warmup --env=prod
  ```
- Nouvelle section **« Admin et production »** dans le README.
- **Hors périmètre** : image Docker autonome (code copié dans l'image) et hébergement, car la cible de déploiement n'est pas définie.

---

## 5. Suppression du code Messenger mort (P1)

### Constat
`symfony/messenger` n'est pas installé. `ImportJobHandler` n'est pas tagué comme handler, et `$this->container->has('message_bus')` est toujours faux dans un `AbstractController`. Ce code n'est donc **jamais exécuté** : seul `ImportManager::start()` (nohup + PID) tourne vraiment.

### Ce qui sera fait
- Supprimer `src/Message/ImportJobMessage.php`, `src/MessageHandler/ImportJobHandler.php` et `src/Exception/JobCancelledException.php` (utilisé uniquement par le handler).
- Dans `AdminImportController::start()`, supprimer la branche `message_bus` et le `use App\Message\ImportJobMessage`.
- **Aucun changement fonctionnel** : le chemin réellement utilisé reste le même.

---

## 6. Préparation des jobs centralisée dans `ImportManager` (P1)

### Constat
La création du fichier de log est écrite à trois endroits : dans `AdminImportController::start()` (le « placeholder », lignes ~177-194), dans `ImportManager::start()` et dans le handler supprimé. Le contrôleur contient donc de la logique métier.

### Ce qui sera fait
- `ImportManager::create(string $command, array $params): ImportJob` :
  - calcule `effectiveOptions()` ;
  - fait persist et flush du `ImportJob` ;
  - crée le log provisoire et renseigne `logPath` et `logPreview`.
- Une méthode privée `newLogPath()`, réutilisée par `start()` quand aucun log n'existe.
- `AdminImportController::start()` se réduit à : valider la commande, `create()`, `start()`, un flash et une redirection.

---

## 7. DTO, `#[MapQueryString]` et `#[MapRequestPayload]` (P1)

### Constat
`HomeController::recherche()` et `searchTextes()` lisent `$request->query->get('q')`. `AdminImportController::start()` lit `$request->request->all()` à la main. Rien n'est validé.

### Ce qui sera fait
- `composer require symfony/serializer symfony/validator`.
- `src/Dto/RechercheQuery.php` :
  ```php
  final readonly class RechercheQuery
  {
      public function __construct(
          #[Assert\Length(max: 200)]
          public string $q = '',
      ) {}
  }
  ```
  Il est utilisé via `#[MapQueryString] RechercheQuery $query = new RechercheQuery()` dans les deux actions d'API. Le `trim()` est conservé.
- `src/Dto/StartImportPayload.php` :
  ```php
  final readonly class StartImportPayload
  {
      public function __construct(
          #[Assert\NotBlank]
          public string $command = '',
          public array $params = [],
      ) {}
  }
  ```
  Il est utilisé via `#[MapRequestPayload]` dans `start()`, qui accepte aussi le `x-www-form-urlencoded`. La vérification contre `self::COMMANDS` et le flash « Commande inconnue » sont conservés.
- Comportement : `q` de plus de 200 caractères donne une **422**. Les appels normaux ne changent pas.

---

## 8. Monolog (P1)

- `composer require symfony/monolog-bundle`. La recette crée `config/packages/monolog.yaml`, et les logs vont dans `var/log/dev.log` (dev), `var/log/test.log` (test) et en JSON sur stderr (prod).
- Ça permet d'appliquer la consigne d'AGENTS.md : « lire `var/log/dev.log` avant de modifier le code ».

---

## 9. Qualité : php-cs-fixer et CI (P1)

- `composer require --dev friendsofphp/php-cs-fixer`.
- `.php-cs-fixer.dist.php` : ruleset `@Symfony`, appliqué à `src/` et `tests/`. Ajouter `.php-cs-fixer.cache` au `.gitignore`.
- Cibles Make :
  - `make cs` lance `vendor/bin/php-cs-fixer fix` ;
  - `make lint` lance `lint:container`, `lint:twig templates/` et `lint:yaml config/`.
- La première exécution de `fix` fait l'objet d'un **commit séparé** (gros diff de mise en forme uniquement).
- `.github/workflows/ci.yml` (le remote est sur GitHub) :
  - service `postgres:16` ;
  - `shivammathur/setup-php` (PHP 8.4, extensions `pdo_pgsql` et `zip`) ;
  - `composer install` ;
  - `php-cs-fixer fix --dry-run --diff` ;
  - `lint:container`, `lint:twig` et `lint:yaml` ;
  - `doctrine:migrations:migrate --env=test -n`, puis `vendor/bin/phpunit`.

---

## Tests automatisés

### À adapter
- **`tests/Controller/AdminImportControllerTest.php`** : chaque test existant se connecte d'abord avec
  ```php
  $client->loginUser(new InMemoryUser('admin', null, ['ROLE_ADMIN']));
  ```
  Les soumissions de formulaire via le crawler incluent automatiquement le champ `_token`.

### À créer
| Fichier | Cas testé | Attendu |
|---|---|---|
| `AdminImportControllerTest` | `GET /admin/imports` sans connexion | 302 vers `/login` |
| `AdminImportControllerTest` | utilisateur connecté sans `ROLE_ADMIN` | 403 |
| `AdminImportControllerTest` | `POST /admin/imports/start` sans `_token` | refusé, aucun `ImportJob` en base |
| `AdminImportControllerTest` | `POST /admin/imports/start` avec un mauvais `_token` | refusé, aucun `ImportJob` en base |
| `AdminImportControllerTest` | `POST /admin/imports/clear` sans `_token` | refusé, historique intact |
| `AdminImportControllerTest` | `GET /admin/imports/{id}/download` sans connexion | 302 vers `/login` |
| `SecurityControllerTest` | `GET /login` | 200, formulaire présent |
| `SecurityControllerTest` | soumission avec le bon mot de passe | redirection vers `/admin/imports`, page accessible |
| `SecurityControllerTest` | soumission avec un mauvais mot de passe | retour sur `/login` avec un message d'erreur |
| `SecurityControllerTest` | `/logout` | `/admin/imports` redevient inaccessible |
| `ImportManagerTest` | `create()` | job persisté, fichier de log créé, options inconnues filtrées |
| `HomeControllerTest` | `/api/recherche?q=<201 caractères>` | 422 |
| `HomeControllerTest` | `/api/recherche?q=loi` et `/api/textes?q=loi` | 200, JSON inchangé |

Pour le test de connexion, `.env.test` reçoit `ADMIN_PASSWORD_HASH` : le hash d'un mot de passe de test connu (par exemple `admin-test`), généré avec `security:hash-password`.

### Lancer
```bash
make test
# ou ciblé :
docker compose exec php vendor/bin/phpunit tests/Controller/AdminImportControllerTest.php
docker compose exec php vendor/bin/phpunit --filter Security
```

---

## Vérification manuelle pas à pas

### A. Contrôles statiques
```bash
docker compose exec php bin/console lint:container
docker compose exec php bin/console lint:twig templates/
docker compose exec php bin/console lint:yaml config/
docker compose exec php bin/console debug:router | grep -E 'app_login|app_logout|admin_'
docker compose exec php bin/console debug:firewall main
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff   # doit être vide
```

### B. Authentification (navigateur, http://localhost:8000)
1. Page d'accueil, `/textes/…` et `/partis` : accessibles sans connexion (**pas de régression**).
2. `/admin/imports` : **redirige vers `/login`**.
3. Mauvais mot de passe : message d'erreur, toujours sur `/login`.
4. Bon mot de passe (celui hashé dans `.env.local`) : arrivée sur `/admin/imports`.
5. « Se déconnecter », puis retour sur `/admin/imports` : nouvelle redirection vers `/login`.
6. Avec `ADMIN_PASSWORD_HASH` vide : aucune connexion possible.

### C. CSRF
1. Connecté, ouvrir les DevTools, supprimer le champ `_token` du formulaire de lancement, puis soumettre : **refusé** et aucun nouveau job dans le tableau.
2. Sans connexion, en ligne de commande :
   ```bash
   curl -i -X POST http://localhost:8000/admin/imports/start -d 'command=app:import-senat'
   ```
   Attendu : 302 vers `/login` et aucun job créé (`make db-shell`, puis `SELECT count(*) FROM import_job;` avant et après).

### D. Fonctionnement de l'admin (non-régression)
1. Connecté, lancer un petit job, par exemple « Import Sénat » avec `limit=1`.
2. Le job apparaît en `running`, la progression et le log défilent (SSE `stream-all`).
3. Télécharger le log : le fichier contient bien la sortie.
4. Lancer un autre job puis l'**annuler** : statut annulé, processus arrêté (`ps aux` dans le conteneur).
5. « Vider l'historique » : les jobs finis disparaissent, ceux en cours restent.

### E. Dépendances de prod
```bash
docker compose exec php composer install --no-dev --optimize-autoloader
docker compose exec php bin/console cache:clear
# lancer un job depuis l'admin : aucune erreur "Class Process not found"
docker compose exec php composer install   # remettre les dépendances de dev
```

### F. Prod
```bash
make up-prod
docker compose exec php bin/console about | grep -E 'Environment|Debug'   # prod / false
docker compose exec php bin/console secrets:list --env=prod --reveal      # APP_SECRET, ADMIN_PASSWORD_HASH
```
Puis rejouer les étapes B et D. Une erreur volontaire (`/textes/999999`) doit afficher la page 404 personnalisée, sans stack trace.

### G. Logs
- Après quelques requêtes en dev, `var/log/dev.log` existe et se remplit.

### H. CI
- Pousser une branche et ouvrir une PR : le workflow GitHub Actions passe au vert (cs, lints, migrations, phpunit).

---

## Ordre des commits

1. `symfony/process`, sécurité, CSRF et tests (P0 §1-3)
2. Vault, `compose.prod.yaml`, `make up-prod` et README (P0 §4)
3. Suppression de Messenger et `ImportManager::create()` (P1 §5-6)
4. DTO avec Serializer et Validator (P1 §7)
5. Monolog (P1 §8)
6. php-cs-fixer (configuration), puis la mise en forme seule dans un commit séparé, puis la CI (P1 §9)

Chaque commit doit laisser `make test` au vert.
