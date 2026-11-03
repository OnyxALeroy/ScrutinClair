Admin et production
===================

Pour démarrer la stack en production (mode `prod`) :

1. Générer les clés de secrets et définir APP_SECRET et ADMIN_PASSWORD_HASH :

```bash
docker compose exec php bin/console secrets:generate-keys --env=prod
docker compose exec php bin/console secrets:set APP_SECRET --env=prod --random
docker compose exec php bin/console security:hash-password 'votreMotDePasse'
# coller le hash dans le vault ou dans .env.local
```

2. Démarrer la stack prod :

```bash
make up-prod
```

La clé privée `config/secrets/prod/prod.decrypt.private.php` n'est pas versionnée :
copiez-la sur le serveur ou fournissez `SYMFONY_DECRYPTION_SECRET`.
