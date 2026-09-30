# План: правило «пробег не больше 400 000 км, иначе решение review» (MILEAGE)

Запрос: «добавить в расчёт решения по заявке проверку "пробег авто не больше 400 000 км,
иначе решение review"».

Основание: `docs/setup/code_map.md` (раздел «Если добавить правило…», строки 54–101),
отчёт скаута по всем упоминаниям mileage, прямая проверка кода (номера строк — на момент
планирования, при правках сдвинутся; ниже — якоря по смыслу).

Ключевой факт: пробег сегодня читается только в `ApplicationValidator` (жёсткий диапазон
0…500 000, строки 43–46) и нигде не влияет на `approve/review/reject`. Новое правило —
мягкое понижение approve → review при пробеге > 400 000; жёсткий потолок 500 000 остаётся.

## Файлы

Трогаем ровно шесть файлов. Всего, чего нет в списке, при реализации трогать нельзя.

| # | Файл | Что и где меняется |
|---|---|---|
| 1 | `backend/config/rules.php` | В блоке `vehicle` (строки 20–24), после `'max_mileage_km' => 500000,` (строка 23) добавить ключ `'max_mileage_review_km' => 400000,` с комментарием: 500 000 — жёсткий потолок валидации, 400 000 — порог понижения approve → review (граница включительная: 400 000 ещё approve). |
| 2 | `backend/src/Domain/AssessmentService.php` | Три правки: (а) конструктор (строки 16–22) — новый 5-й параметр `private readonly array $rules` (PHPDoc: `@param array<string,mixed> $rules`); (б) в `assess()` — после `$decision = $this->decisionEngine->decide($ltv);` (строка 33) и до `return` (строки 35–41) новое правило: если `$decision === DecisionEngine::APPROVE` и `$input['mileage'] > $this->rules['vehicle']['max_mileage_review_km']` — `$decision = DecisionEngine::REVIEW`; (в) PHPDoc класса (строки 7–13) — дописать шаг «подавление approve при пробеге выше порога». |
| 3 | `backend/src/AppFactory.php` | В конструкции `new AssessmentService(...)` (строки 30–39) передать `$rules` пятым аргументом. |
| 4 | `tests/Unit/AssessmentServiceTest.php` | В `setUp()` (строки 19–30) передать `$rules` (уже загружен на строке 21) в `AssessmentService`; хелпер `payload()` (строки 32–43) получает третий параметр `int $mileage = 96000` (строка 38 — `'mileage' => $mileage`); добавить новые тест-методы из раздела «Тесты». Существующие три теста не меняются. |
| 5 | `tests/Unit/ApplicationValidatorTest.php` | Добавить тест-методы на пустой пробег и жёсткий потолок из раздела «Тесты». Хелпер `validPayload(array $overrides)` (строки 29–39) уже позволяет переопределять поля — его не трогаем. |
| 6 | `docs/setup/code_map.md` | Синхронизация фактов после реализации: раздел-черновик «Если добавить правило…» (строки 54–85) заменить описанием фактического поведения; утверждение строки 99 «на итоговое approve/review/reject пробег сейчас никак не влияет» — исправить. |

Явно **не** трогаем (всё остальное): `ApplicationValidator.php` (включая проверку
`max_mileage_km`, строки 43–46, и текст её ошибки), `DecisionEngine.php`,
`LtvCalculator.php`, `VehicleAge.php`, `VinValidator.php`, `ValidationException.php`,
`ApplicationController.php`, `ApplicationRepository.php`, `backend/src/Support/Json.php`,
`frontend/*`, `db/schema.sql`, `db/seed.sql`, `composer.json`, `phpunit.xml`,
`docker-compose.yml`, `Makefile`.

## Шаги

1. **`backend/config/rules.php`**: добавить `'max_mileage_review_km' => 400000` в блок
   `vehicle` (шаг 1 из таблицы). Поведение пока не меняется — ключ никто не читает.
2. **`backend/src/Domain/AssessmentService.php`**: новый параметр конструктора
   `private readonly array $rules`; правило в `assess()` между `decide(...)` и `return`;
   обновить PHPDoc класса. Новая сигнатура:

   ```php
   public function __construct(
       private readonly ApplicationValidator $validator,
       private readonly LtvCalculator $ltvCalculator,
       private readonly DecisionEngine $decisionEngine,
       private readonly VehicleAge $vehicleAge,
       private readonly array $rules,
   )
   ```

   Правило словами (не код): если решение после `decide()` — `approve` и нормализованный
   `$input['mileage']` **строго больше** `rules['vehicle']['max_mileage_review_km']`,
   решение становится `review`. Обязательное условие: до формирования массива ответа,
   чтобы `approved_limit` (строка 39: `approve → requested_amount, иначе 0`) автоматически
   обнулился.
3. **`backend/src/AppFactory.php`**: передать `$rules` пятым аргументом в
   `new AssessmentService(...)`. Шаги 2–4 — одна атомарная правка: смена сигнатуры
   одновременно ломает проводку и тест (см. риски, п. 2).
4. **`tests/Unit/AssessmentServiceTest.php`**: в `setUp()` передать `$rules`; расширить
   `payload()` параметром `mileage` с дефолтом 96000 — существующие три теста остаются
   зелёными без правок.
5. **Добавить новые тесты** из раздела «Тесты»: 6 методов в `AssessmentServiceTest`,
   4 метода в `ApplicationValidatorTest`.
6. **Прогнать** `make lint` и `make test` — оба зелёные, включая все существующие тесты.
7. **Smoke** (только если стек уже поднят; ради него не поднимать и не пересеивать,
   `make seed` и `scripts/reset_db.sh` не запускать): `curl POST /api/ltv` с mileage 400000
   → `decision: approve`, с mileage 400001 → `decision: review` (payload в «Тестах»).
8. **`docs/setup/code_map.md`**: синхронизировать (таблица «Файлы», п. 6).

## Тесты

Новых файлов нет — только методы в двух существующих классах. Прогон: `make test`.

### Граничные значения — отдельными строками

- 399 999 км — последнее значение до порога → approve;
- 400 000 км — ровно порог («не больше 400 000» = ≤ 400 000) → approve;
- 400 001 км — первое значение за порогом → review, approved_limit = 0;
- пустой пробег (ключ `mileage` отсутствует или null) → `ValidationException` с ошибкой
  по полю `mileage` (HTTP 422), до расчёта решения не доходит.

### `tests/Unit/AssessmentServiceTest.php` (через `assess()`; суммы 450 000/900 000 = LTV 50% — зона approve)

| Метод-кандидат | Вход | Ожидание |
|---|---|---|
| testKeepsApproveWhenMileageBelowReviewThreshold | mileage 399 999, LTV 50% | `approve`, approved_limit = 450 000 |
| testKeepsApproveAtReviewThreshold | mileage 400 000, LTV 50% | `approve`, approved_limit = 450 000 |
| testDowngradesApproveToReviewAboveReviewThreshold | mileage 400 001, LTV 50% | `review`, approved_limit = 0 |
| testKeepsReviewWhenMileageAboveReviewThreshold | mileage 450 000, LTV 75% (675 000/900 000) | `review`, approved_limit = 0 (уже review по LTV — не меняется) |
| testKeepsRejectWhenMileageAboveReviewThreshold | mileage 450 000, LTV 95% (855 000/900 000) | `reject`, approved_limit = 0 (правило не отменяет reject) |
| testReviewThresholdDoesNotExceedHardCeiling | правила из `rules.php`: `max_mileage_review_km` ≤ `max_mileage_km` | инвариант связки порогов выполняется |

### `tests/Unit/ApplicationValidatorTest.php`

| Метод-кандидат | Вход | Ожидание |
|---|---|---|
| testRejectsMissingMileage | payload без ключа `mileage` | `ValidationException`, ключ `mileage` в `errors()` |
| testRejectsNullMileage | `mileage` = null | `ValidationException`, ключ `mileage` (`??` трактует null как отсутствующий → −1) |
| testAcceptsMileageAtHardCeiling | mileage 500 000 | проходит валидацию — потолок `max_mileage_km` не сдвинут |
| testRejectsMileageAboveHardCeiling | mileage 500 001 | `ValidationException`, ключ `mileage` — жёсткий потолок не заменён новым правилом |

Кейс «пустая строка» (`''` → валидатор приводит к 0 км и пропускает) тестом намеренно
не закрепляем — это существующее поведение нормализации, вынесено в риски (п. 6) и в
вопрос заказчику (п. 3).

### Проверки-команды

| Проверка | Команда | Ожидание |
|---|---|---|
| Синтаксис PHP | `make lint` | без ошибок |
| Юнит-тесты | `make test` | все зелёные, включая существующие (их дефолтные пробеги 84 000/96 000 < 400 000) |

Smoke, если стек поднят:

```bash
curl -sS -X POST http://localhost:8080/api/ltv -H 'Content-Type: application/json' \
  -d '{"vin":"XTA21099998765432","year":2022,"mileage":400001,"market_value":900000,"requested_amount":450000,"term_months":24}'
# → decision "review", approved_limit 0; с mileage 400000 → decision "approve", approved_limit 450000
```

## Риски

1. **Связка с существующей проверкой `max_mileage_km`** (`ApplicationValidator.php:43–46`,
   потолок 500 000). Это другой механизм: 400 001–500 000 → review (мягкое правило в
   `AssessmentService`), > 500 000 → `ValidationException` → HTTP 422 (жёсткий отказ до
   расчёта). Нельзя: (а) заменить потолок 500 000 на 400 000 — это превратило бы review в
   отказ; (б) реализовать новое правило в `ApplicationValidator` как ошибку поля — тот же
   эффект; (в) менять текст ошибки потолка. Инвариант
   `max_mileage_review_km ≤ max_mileage_km` закреплён тестом: если конфиг правят без кода
   и диапазон правила исчезает — прогон краснеет.
2. **Смена сигнатуры конструктора `AssessmentService`** ломает обе точки создания:
   `AppFactory::create()` и `AssessmentServiceTest::setUp()`. Пропустить любую —
   `ArgumentCountError` (в рантайме 500, в тестах красный прогон). Поэтому шаги 2–4 —
   одна атомарная правка.
3. **Порядок вычисления**: правило обязано стоять после `decide()` и до формирования
   ответа. Иначе при понижении до review `approved_limit` останется равным запрошенной
   сумме (бизнес-ошибка). Расширять `DecisionEngine::decide()` не нужно — code_map прямо
   рекомендует уровень оркестратора.
4. **Двусмысленность формулировки** «иначе решение review»: буквальное чтение — «при
   пробеге > 400 000 любое решение становится review», т.е. и reject понижался бы.
   План следует интерпретации code_map: подавляется только approve. Расхождение с
   замыслом заказчика меняет логику и тесты (вопрос 1).
5. **Включительность границы**: «не больше 400 000» = ≤ 400 000 (400 000 — ещё approve).
   В том же коде порог LTV использует строгое неравенство (`ltv < approve_max` → approve),
   стили различаются — комментарий в `rules.php` должен снимать путаницу. Если имелось в
   виду «строго меньше» — случай 400 000 переезжает в review (вопрос 2).
6. **Неоднозначность «пустого пробега»**: отсутствие ключа и null → −1 → 422; пустая
   строка `''` → `(int)''` = 0 → проходит как 0 км. Существующее поведение нормализации,
   план его не меняет и тестом не закрепляет (вопрос 3). С фронтенда пустой пробег
   фактически не отправляется (поле `required`, дефолт 84 000).
7. **Ответ API не объясняет причину review**: пониженное из-за пробега решение неотличимо
   от review по LTV ни для клиента, ни для оператора. Поле `reason` — отдельное изменение
   контракта, в план не входит (вопрос 4).
8. **Рассинхрон документации**: `code_map.md` после реализации описывает несуществующее
   состояние («пробег на решение не влияет», черновик правила). Без шага 8 следующие
   планировщики будут читать ложные факты.
9. **Существующие тесты**: не затронуты (дефолтные пробеги 84 000/96 000 ниже порога;
   `DecisionEngineTest`, `LtvCalculatorTest`, `VinValidatorTest` к пробегу отношения не
   имеют). Feature-тестов в репозитории нет (`tests/Feature/` пуст) — регресс на уровне
   HTTP покрыть нечем, покрытие юнит-уровневое + smoke вручную.
10. **Данные**: seed-значения 20 000–296 000 км ниже порога; схема БД не меняется
    (`vehicles.mileage_km INT UNSIGNED`), миграций и пересева не требуется.

## Не входит

- Правки `ApplicationValidator` (включая проверку `max_mileage_km` и её текст),
  `DecisionEngine`, `LtvCalculator`, `VehicleAge`, `VinValidator`, `ValidationException`.
- Изменение контракта ответа HTTP (поле `reason` и т.п.) и фронтенда (подсказки в форме,
  пояснение причин в результатах).
- Правки БД: `schema.sql`, `seed.sql`, миграции, `make seed`.
- Новые Feature/HTTP-тесты.
- Задача LOAN-12 (лимит по `ltv_by_age`). Побочный плюс: инъекция `$rules` в
  `AssessmentService` готовит для неё почву.
- Влияние пробега на LTV или `approved_limit` сверх обнуления лимита при понижении
  до review.

## Вопросы, на которые без заказчика не ответить

1. **Семантика правила**: только понижение approve → review, или при пробеге > 400 000
   любое решение (включая reject) должно становиться review? План: только approve.
2. **Граница**: ровно 400 000 км — ещё approve («не больше» = ≤)? План: да; при «строго
   меньше» случай 400 000 уходит в review.
3. **Пустой пробег**: устраивает ли текущее поведение — отсутствие/null → 422, но пустая
   строка → 0 км и проходит валидацию? Нужно ли `''` тоже отвергать (тогда это отдельная
   правка `ApplicationValidator`)?
4. **Причина review в ответе**: нужен ли reason/message («высокий пробег»), или решение
   без причины устраивает? План: без причины.
5. **Единый порог**: 400 000 одинаков для всех авто независимо от возраста и стоимости,
   или будут уточнения (например, мягче для машин младше 3 лет)? План: единый порог
   из `rules.php`.
