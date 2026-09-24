# kilo_hello

Готов.

1) Сервис: учебный сервис **предварительной оценки заявки на заём под ПТС** (carmoney-lab) — принимает заявку (VIN, год, пробег, оценочная стоимость, сумма, срок), считает LTV и возвращает решение `approve` / `review` / `reject`; данные синтетические, репозиторий публичный.
2) Запуск/проверка: `make up` (docker compose up -d --build — сервис на http://localhost:8080, MySQL 8 на ${DB_PORT:-3307}); `make down`, `make ps`, `make logs`, `make seed` (`docker compose exec -T db mysql ... < db/seed.sql`), `make install` (`composer install`), `make test` (vendor/bin/phpunit локально, иначе `docker compose run --rm --no-deps backend vendor/bin/phpunit`), `make lint` (`find backend tests -name '*.php' -print0 | xargs -0 -n1 php -l`), `make help`. Compose-сервисы: `backend` (php -S 0.0.0.0:8080 -t backend/public backend/public/router.php, порт ${APP_PORT:-8080}:8080) и `db` (mysql:8.0, init-скрипты `./db/schema.sql`, `./db/seed.sql`).
3) Решение `approve` / `review` / `reject` по заявке считается в `backend/src/Domain/DecisionEngine.php` (папка `backend/src/Domain/`; рядом `LtvCalculator.php`, `AssessmentService.php`, `VehicleAge.php`, `VinValidator.php`, `ApplicationValidator.php`, `ValidationException.php`).

модель: training-2026-09-minimax-m3
