# Scout вернул

Разведка завершена. Ниже — полный структурированный список.

---

# Разведка: где читается пробег (mileage)

Рабочая директория: `/Users/aleksandr/CommonProjects/carmoney-lab`

## Главный вывод

**Пробег в бизнес-логике сейчас НЕ используется.** В коде он только:
1. валидируется как «жёсткий диапазон 0…500 000 км» (если выйдет — заявка целиком отклоняется),
2. нормализуется в `int`,
3. сохраняется в БД,
4. читается обратно из БД в `find()`.

Ни в `AssessmentService`, ни в `LtvCalculator`, ни в `DecisionEngine` побег **не читается** — на итоговое `approve/review/reject` не влияет. Это прямо зафиксировано в `docs/setup/code_map.md:99` («На итоговое approve/review/reject робег сейчас никак не влияет»).

---

## 1. Backend (PHP)

### `backend/src/Domain/ApplicationValidator.php` — единственная бизнес-валидация пробега
- **L22** — `array{... mileage:int ...}` в PHPDoc `@return` сигнатуре `validate()` — поле присутствует в нормализованном выходе.
- **L43** — `$mileage = (int) ($payload['mileage'] ?? -1);` — чтение и приведение к `int` из входного payload.
- **L44** — `if ($mileage < 0 || $mileage > $this->rules['vehicle']['max_mileage_km'])` — **сравнение с порогом из `rules.php` (`max_mileage_km = 500000`)**; единственное место, где порог реально читается.
- **L45** — `$errors['mileage'] = sprintf('Пробег от 0 до %d км', ...)` — текст ошибки, тоже с подстановкой порога.
- **L78** — `'mileage' => $mileage` — пробег кладётся в нормализованный массив, который дальше уходит в `AssessmentService::assess()` и `ApplicationRepository::save()`. **Но дальше по коду никто это `mileage` не читает** (см. grep по `backend/src` — 0 совпдений в `LtvCalculator`, `DecisionEngine`, `AssessmentService`).

### `backend/src/Repository/ApplicationRepository.php` — проброс в БД и чтение обратно
- **L19** — `array{... mileage:int ...}` в PHPDoc параметра `save()`.
- **L38** — SQL `INSERT INTO vehicles (... mileage_km, ...)` — колонка хранения.
- **L39** — `VALUES (... :mileage, ...)` — именованный плейсхолдер.
- **L45** — `':mileage' => $input['mileage']` — биндинг значения (пробег из нормализованного входа).
- **L68** — `v.mileage_km,` в SELECT списке `find()` — пробег читается обратно из БД при получении заявки по id (для возможного возврата клиенту/оператору; в текущем HTTP-слое это значение никуда не мапится — см. `backend/src/Http/*` без упоминаний mileage).

### `backend/config/rules.php` — объявление порога
- **L23** — `'max_mileage_km' => 500000,` в блоке `vehicle`. Используется только в `ApplicationValidator::validate()` (L44–L45). Других порогов про пробег (например, отдельного «review-порога» 400 000) в конфиге **нет** — это и есть учебная задача из `docs/setup/code_map.md:54–79`.

### `backend/src/Domain/{LtvCalculator,DecisionEngine,AssessmentService,VehicleAge,VinValidator}.php`
- **0 упоминаний** `mileage` / `mileage_km` / `пробег`. Подтвержден grep'ом по `backend/src` — все 10 совпадений только в `ApplicationValidator` и `ApplicationRepository`.

### `backend/src/Http/*` (контроллеры/роуты)
- **0 упоминаний** `mileage`. Контроллеры читают из ответа `assess()`/`find()` и не используют побег.

---

## 2. Frontend (JS / HTML)

### `frontend/index.html` — поле ввода
- **L30** — `<label for="mileage">Пробег, км</label>` — UI-метка.
- **L31** — `<input id="mileage" name="mileage" type="number" value="84000" required>` — `<input>`, имя поля в форме = `mileage`, дефолт `84000`.

### `frontend/app.js` — сборка payload
- **L8** — `const NUMERIC_FIELDS = ['year', 'mileage', 'market_value', 'requested_amount', 'term_months'];` — `mileage` помечено как числовое поле.
- **L14** — `payload[key] = NUMERIC_FIELDS.includes(key) ? Number(value) : String(value).trim();` — здесь значение из формы `mileage` приводится к `Number` и попадает в JSON-payload, который уходит в `POST /api/applications` или `POST /api/ltv`.

> Пробег **не выводится** в UI результата (в `showResult()` рендерятся `decision / ltv / vehicle_age / approved_limit / id`, без mileage).

---

## 3. DB (SQL)

### `db/schema.sql`
- **L22** — `mileage_km INT UNSIGNED NOT NULL,` — колонка хранения в таблице `vehicles`. Единственное место схемы, где фигурирует пробег.

### `db/seed.sql`
- **L31** — `INSERT INTO vehicles (application_id, vin, make, model, production_year, mileage_km, market_value) VALUES` — список колонок при сидинге.
- **L32–L55** — 24 синтетических значеня `mileage_km` (от 20 000 до 296 000, шаг 12 000). Это учебные данные, в рантайме не читаютя приложением.

---

## 4. Тесты (PHPUnit)

### `tests/Unit/ApplicationValidatorTest.php`
- **L34** — `'mileage' => 84000,` в фабричном `validPayload()` — значение по умолчанию для всех валидационных тестов. Сами тесты не ассертируют пробег отдельно (валидность полей проверяется косвенно).

### `tests/Unit/AssessmentServiceTest.php`
- **L38** — `'mileage' => 96000,` в фабричном `payload()` — дефолт для тестов `assess()`. Пробег подаётся только чтобы пройти валидацию; в ассертах (`testApprovesLowLtvAndSetsLimitToRequestedAmount`, `testSendsMiddleLtvToReviewWithZeroLimit`, `testRejectsHighLtv`) проверяются `ltv / decision / approved_limit / vehicle_age` — **mileage не проверяется ни в одном ассерте**.

### Прочие тесты
- `tests/Feature/*` и `tests/Unit/*` (кроме двух файлов выше) — 0 упоминаний `mileage`.

---

## 5. Конфиги / Makefile / Docker
- `docker-compose.yml`, `Makefile`, `phpunit.xml`, `composer.json`, `kilo.jsonc`, `.env.example` — **0 упоминаний** `mileage`. Пробег ни в одном конфиге/скрипте не фигурирует.

---

## 6. Документация (контекст, не код рантайма)

Эти файлы содержат толко описание/правила, в рантайме не выполняются; привожу для полноты картин:

- `AGENTS.md:5` — описание сервиса: «(VIN, год, пробег, оценочная стоимость, сумма, срок)».
- `README.md:6,35,75,140` — пример curl c полем `mileage` и описание фичи.
- `docs/README.md:4`, `docs/spec/README.md:5–10`, `docs/plan/README.md:6–14`, `docs/intent/README.md:5–17`, `docs/hw1/README.md:11,14` — упоминания ID задачи `MILEAGE` и формулировка ДЗ.1 «Пробег ≤ 400 000» (будущее правило, ещё не реализовано).
- `docs/setup/code_map.md:25,38,54–101` — **карта текущего состояния**: явно фиксирует, что сейчас ипользуется только `max_mileage_km` (жёсткий потолок), и что поля `mileage` в БД/репозитории/справочнике `ltv_by_age` — **нет** (т.е. в LTV-расчёте пробег не участвует).
- `docs/sources/LOAN-12.md:9,36`, `docs/sources/CASE-08.md:15,23`, `docs/sources/client_note.md:24,40` — учебные интервью/кейсы, упоминающие пробег как требование к фиче. В коде **не спользуются**.

---

## Сводка: сравнение с порогами из `rules.php`

| Где | Что сравнивается | Порог | Файл:строка |
|---|---|---|---|
| `ApplicationValidator::validate` | `$mileage` vs `rules.vehicle.max_mileage_km` (жёсткий потолок, иначе `ValidationException` — заяка отклоняется целиком) | `500000` | `backend/src/Domain/ApplicationValidator.php:44` (правило в `backend/config/rules.php:23`) |

Других порогов про побег (например, «пробег > 400 000 → review») в `rules.php` **нет** — это и есть учебная задача ДЗ.1, описанная в `docs/setup/code_map.md:54–79` и в `docs/plan/plan_MILEAGE.md`.

---

## Итог одним предложением

Пробег в текущем коде **только валидируется на жёсткий диапазон 0…500 000** (`ApplicationValidator.php:43–45`), **нормализуется и сохраняется** в `vehicles.mileage_km` (`ApplicationRepository.php:38–45, 68`) и **вводится пользователем** в форме (`index.html:3031` + `app.js:8,14`); в `LtvCalculator`, `DecisionEngine`, `AssessmentService` и HTTP-слое **не читается вообще** — на решение `approve/review/reject` не влияет.


# Ответ без вызова Scout

Planner не вызывал Scout