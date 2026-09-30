# План: правило «пробег не больше 400 000 км, иначе решение review» (MILEAGE)

Запрос заказчика: «добавить в расчёт решения по заявке проверку "пробег авто не
больше 400 000 км, иначе решение review"».

Основание: `docs/spec/spec_MILEAGE.md` (REQ-MILEAGE-01…10, AC-MILEAGE-01…16 —
что считается сделанным), `docs/intent/intent_MILEAGE.md` и интервью
`docs/intent/grill_MILEAGE.md` (все open questions закрыты); дополнительно —
`docs/setup/code_map.md` (раздел «Если добавить правило…», строки 54–101),
отчёт скаута по всем упоминаниям mileage, прямая проверка кода. Номера строк —
якоря по смыслу, при правках сдвинутся; перепроверены по коду 2026-09-30.

Ключевой факт: пробег сегодня читается только в `ApplicationValidator` (жёсткий
диапазон 0…500 000, строки 43–46) и нигде не влияет на `approve/review/reject`.
Новое правило — мягкое понижение approve → review при пробеге > 400 000; жёсткий
потолок 500 000 остаётся. Шкала из спеки (раздел 1, «Входит», п. 2): 0–400 000 —
решение по LTV; 400 001–500 000 — approve невозможен (понижение до review);
выше 500 000 — HTTP 422 (существующий потолок `max_mileage_km`).

## Сверка со спекой (ДЗ.1) — что дополнено

Интервью подтвердило все дефолты плана (grill, строки 51–53): расхождений в
семантике нет. Дополнено по результатам сверки:

1. Якоря по коду перепроверены, включая `code_map.md`, `DecisionEngine.php` и
   intent — номера строк и поведение совпадают.
2. Добавлены 5 тестов под критерии приёмки, не покрытые планом: AC-MILEAGE-03
   (review по LTV ровно на пороге), AC-MILEAGE-07 (500 000 км → review),
   AC-MILEAGE-14 (одинаковая форма ответов двух review), AC-MILEAGE-15
   (независимость порога от возраста), AC-MILEAGE-16 (ltv не зависит от пробега).
3. AC-MILEAGE-11 (пустая строка `''` → 0 км, approve) юнит-тестом **не**
   закрепляется — явное решение заказчика (интервью, вопрос 4: «не меняем и
   тестом не закрепляем», покрыто REQ-MILEAGE-06). Вместо теста — smoke-проверка.
4. Раздел открытых вопросов переписан: все 6 вопросов закрыты интервью,
   ответы привязаны к REQ; открытых вопросов нет, план готов к реализации.
5. Добавлена таблица «Покрытие критериев приёмки»: каждый AC-01…16 → проверка.

Находки сверки, не меняющие спеку и объём правок:

- `code_map.md` внутренне противоречив: текст ставит правило «**между**
  `LtvCalculator::calculate(...)` и `DecisionEngine::decide(...)`» (строка 58),
  а набросок в том же разделе — после `decide()`, с проверкой
  `$decision === DecisionEngine::APPROVE` (строки 75–81). План следует наброску
  и спеке: правило после `decide()`, до формирования ответа (иначе REQ-MILEAGE-03
  требует сравнивать решение, которого ещё нет). Противоречие снимается на шаге 8.
- Существующее расхождение документации с кодом в `DecisionEngine`: PHPDoc класса
  (строки 10–12) и AGENTS.md описывают approve как `LTV <= approve_max`, код
  использует строго `<` (строка 32). К MILEAGE не относится и в этой задаче не
  правится (REQ-MILEAGE-10, файл вне списка); тесты плана берут значения LTV
  вдали от порога 60.0, поэтому расхождение на них не влияет.

## Файлы

Трогаем ровно шесть файлов. Всего, чего нет в списке, при реализации трогать нельзя.

| # | Файл | Что и где меняется |
|---|---|---|
| 1 | `backend/config/rules.php` | В блоке `vehicle` (строки 20–24), после `'max_mileage_km' => 500000,` (строка 23) добавить ключ `'max_mileage_review_km' => 400000,` с комментарием: 500 000 — жёсткий потолок валидации, 400 000 — порог понижения approve → review (граница включительная: 400 000 ещё approve). |
| 2 | `backend/src/Domain/AssessmentService.php` | Три правки: (а) конструктор (строки 16–22) — новый 5-й параметр `private readonly array $rules` (PHPDoc: `@param array<string,mixed> $rules`); (б) в `assess()` — после `$decision = $this->decisionEngine->decide($ltv);` (строка 33) и до `return` (строки 35–41) новое правило: если `$decision === DecisionEngine::APPROVE` и `$input['mileage'] > $this->rules['vehicle']['max_mileage_review_km']` — `$decision = DecisionEngine::REVIEW`; (в) PHPDoc класса (строки 7–13) — дописать шаг «подавление approve при пробеге выше порога». |
| 3 | `backend/src/AppFactory.php` | В конструкции `new AssessmentService(...)` (строки 30–39) передать `$rules` пятым аргументом. |
| 4 | `tests/Unit/AssessmentServiceTest.php` | В `setUp()` (строки 19–30) передать `$rules` (уже загружен на строке 21) в `AssessmentService`; хелпер `payload()` (строки 32–43) получает третий параметр `int $mileage = 96000` (строка 38 — `'mileage' => $mileage`) и четвёртый `?int $year = null` (`null` → текущий дефолт `(int) date('Y') - 4`; нужен для теста независимости от возраста, AC-MILEAGE-15); добавить новые тест-методы из раздела «Тесты». Существующие три теста не меняются. |
| 5 | `tests/Unit/ApplicationValidatorTest.php` | Добавить тест-методы на пустой пробег и жёсткий потолок из раздела «Тесты». Хелпер `validPayload(array $overrides)` (строки 29–39) уже позволяет переопределять поля — его не трогаем. |
| 6 | `docs/setup/code_map.md` | Синхронизация фактов после реализации: раздел «Если добавить правило…» (строки 54–101; набросок встройки — строки 71–84, справка о текущей проверке пробега — строки 88–101) заменить описанием фактического поведения; утверждение строки 99 «на итоговое approve/review/reject пробег сейчас никак не влияет» — исправить. Заодно снять внутреннее противоречие раздела (см. «Находки сверки»): описать фактическое место правила — после `decide()`, до формирования ответа. |

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

   Правило словами (не код): если решение после `decide()` — `approve` и
   нормализованный `$input['mileage']` **строго больше**
   `rules['vehicle']['max_mileage_review_km']`, решение становится `review`
   (REQ-MILEAGE-02; review и reject по LTV не трогаем — REQ-MILEAGE-03).
   Обязательное условие: до формирования массива ответа, чтобы `approved_limit`
   (строка 39: `approve → requested_amount, иначе 0`) автоматически
   обнулился (REQ-MILEAGE-07).
3. **`backend/src/AppFactory.php`**: передать `$rules` пятым аргументом в
   `new AssessmentService(...)`. Шаги 2–4 — одна атомарная правка: смена сигнатуры
   одновременно ломает проводку и тест (см. риски, п. 2).
4. **`tests/Unit/AssessmentServiceTest.php`**: в `setUp()` передать `$rules`; расширить
   `payload()` параметрами `mileage` (дефолт 96000) и `year` (дефолт — как сейчас) —
   существующие три теста остаются зелёными без правок.
5. **Добавить новые тесты** из раздела «Тесты»: 11 методов в `AssessmentServiceTest`,
   4 метода в `ApplicationValidatorTest`.
6. **Прогнать** `make lint` и `make test` — оба зелёные, включая все существующие тесты.
7. **Smoke** (только если стек уже поднят; ради него не поднимать и не пересеивать,
   `make seed` и `scripts/reset_db.sh` не запускать): `curl POST /api/ltv` — кейсы
   в разделе «Тесты» (порог 400 000/400 001, потолок 500 000/500 001, пустая строка).
8. **`docs/setup/code_map.md`**: синхронизировать (таблица «Файлы», п. 6).

## Тесты

Новых файлов нет — только методы в двух существующих классах. Прогон: `make test`.
Суммы: 450 000/900 000 = LTV 50 % (approve), 675 000/900 000 = 75 % (review),
855 000/900 000 = 95 % (reject) — зоны по порогам 60.0/85.0 из `rules.php`,
блок `ltv`. Значения LTV выбраны внутри зон, вдали от порогов 60.0/85.0 —
существующее расхождение оператора approve в документации и коде
(`DecisionEngine::decide`, строго `<`, строка 32) на тесты не влияет (см. «Находки
сверки»). Граничные значения пробега — литералами, как в существующих тестах:
смена порога в конфиге без правки спеки и тестов должна давать красный прогон,
а не молчаливый сдвиг границы.

### Граничные значения — отдельными строками (полная шкала из спеки)

- 399 999 км — последнее значение до порога → approve;
- 400 000 км — ровно порог («не больше 400 000» = ≤ 400 000) → approve;
- 400 001 км — первое значение за порогом → review, approved_limit = 0;
- 450 000 км — середина мягкой зоны 400 001–500 000 → review;
- 500 000 км — ровно жёсткий потолок: валидацию проходит, решение → review;
- 500 001 км — первое значение за потолком → `ValidationException` по полю
  `mileage` (HTTP 422), до расчёта решения не доходит;
- пустой пробег: ключ `mileage` отсутствует или null → `ValidationException`
  по полю `mileage` (HTTP 422); пустая строка `''` → 0 км, проходит (AC-MILEAGE-11,
  тестом не закрепляем — см. риск 4).

### `tests/Unit/AssessmentServiceTest.php` (через `assess()`, с валидацией)

| Метод-кандидат | Вход | Ожидание | AC спеки |
|---|---|---|---|
| testKeepsApproveWhenMileageBelowReviewThreshold | mileage 399 999, LTV 50% | `approve`, approved_limit = 450 000 | AC-MILEAGE-01 |
| testKeepsApproveAtReviewThreshold | mileage 400 000, LTV 50% | `approve`, approved_limit = 450 000 — граница включительная | AC-MILEAGE-02, AC-MILEAGE-12 |
| testKeepsReviewByLtvAtReviewThreshold | mileage 400 000, LTV 75% (675 000/900 000) | `review`, approved_limit = 0 — на пороге и ниже решение определяет LTV | AC-MILEAGE-03 |
| testDowngradesApproveToReviewAboveReviewThreshold | mileage 400 001, LTV 50% | `review`, approved_limit = 0 | AC-MILEAGE-04, AC-MILEAGE-13 |
| testKeepsReviewWhenMileageAboveReviewThreshold | mileage 450 000, LTV 75% | `review`, approved_limit = 0 (уже review по LTV — не меняется) | AC-MILEAGE-05 |
| testKeepsRejectWhenMileageAboveReviewThreshold | mileage 450 000, LTV 95% | `reject`, approved_limit = 0 (правило не отменяет reject) | AC-MILEAGE-06 |
| testDowngradesApproveToReviewAtHardCeiling | mileage 500 000, LTV 50% | `review`, approved_limit = 0 — потолок 500 000 валидацию проходит, правило действует до верхней границы | AC-MILEAGE-07 |
| testDowngradedReviewHasSameShapeAsReviewByLtv | два `assess()`: (LTV 50%, mileage 400 001) и (LTV 75%, mileage 400 000) | оба `review`; наборы ключей результатов совпадают, ключа `reason` нет ни в одном | AC-MILEAGE-14 |
| testReviewThresholdDoesNotDependOnVehicleAge | mileage 400 001, LTV 50%; `year` → возраст 2 года и 15 лет (обе в диапазоне 0–20) | оба `review`, approved_limit = 0 | AC-MILEAGE-15 |
| testMileageDoesNotChangeLtv | одинаковые суммы/авто, mileage 399 999 и 400 001 | `ltv` и `vehicle_age` одинаковы; среди полей ответа API (vehicle_age, ltv, decision, approved_limit) различаются только decision (approve/review) и approved_limit (450 000/0) | AC-MILEAGE-16 |
| testReviewThresholdDoesNotExceedHardCeiling | правила из `rules.php`: `max_mileage_review_km` ≤ `max_mileage_km` | инвариант связки порогов выполняется | защита REQ-MILEAGE-04 |

### `tests/Unit/ApplicationValidatorTest.php`

| Метод-кандидат | Вход | Ожидание | AC спеки |
|---|---|---|---|
| testRejectsMissingMileage | payload без ключа `mileage` | `ValidationException`, ключ `mileage` в `errors()` | AC-MILEAGE-09 |
| testRejectsNullMileage | `mileage` = null | `ValidationException`, ключ `mileage` (`??` трактует null как отсутствующий → −1) | AC-MILEAGE-10 |
| testAcceptsMileageAtHardCeiling | mileage 500 000 | проходит валидацию — потолок `max_mileage_km` не сдвинут | AC-MILEAGE-07 (часть про валидацию) |
| testRejectsMileageAboveHardCeiling | mileage 500 001 | `ValidationException`, ключ `mileage` — жёсткий потолок не заменён новым правилом | AC-MILEAGE-08 |

Кейс «пустая строка» (`''` → валидатор приводит к 0 км и пропускает) тестом
намеренно не закрепляем — явное решение заказчика (интервью, вопрос 4; REQ-MILEAGE-06,
AC-MILEAGE-11). Проверка — smoke ниже; остаточный риск — п. 4 раздела «Риски».

### Проверки-команды

| Проверка | Команда | Ожидание |
|---|---|---|
| Синтаксис PHP | `make lint` | без ошибок |
| Юнит-тесты | `make test` | все зелёные, включая существующие (их дефолтные пробеги 84 000/96 000 < 400 000) |

Smoke, если стек поднят (базовый payload: vin `XTA21099998765432`, year 2022,
market_value 900000, requested_amount 450000, term_months 24; year 2022 —
возраст 4 года на момент 2026-го, при прогоне позже подберите год, чтобы возраст
оставался в допустимом диапазоне 0–20 лет):

```bash
curl -sS -X POST http://localhost:8080/api/ltv -H 'Content-Type: application/json' \
  -d '{"vin":"XTA21099998765432","year":2022,"mileage":400001,"market_value":900000,"requested_amount":450000,"term_months":24}'
# AC-MILEAGE-04: decision "review", approved_limit 0; с mileage 400000 → decision "approve", approved_limit 450000 (AC-01/02)

# AC-MILEAGE-07/08 (потолок 500 000 не сдвинут):
#   mileage 500000 → decision "review"; mileage 500001 → HTTP 422, {"errors":{"mileage":"Пробег от 0 до 500000 км"}}
curl -sS -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8080/api/ltv \
  -H 'Content-Type: application/json' \
  -d '{"vin":"XTA21099998765432","year":2022,"mileage":500001,"market_value":900000,"requested_amount":450000,"term_months":24}'

# AC-MILEAGE-11 (пустая строка → 0 км, вместо юнит-теста; из формы фронтенда
# такой payload не отправляется — кейс API-уровневый):
curl -sS -X POST http://localhost:8080/api/ltv -H 'Content-Type: application/json' \
  -d '{"vin":"XTA21099998765432","year":2022,"mileage":"","market_value":900000,"requested_amount":450000,"term_months":24}'
# → decision "approve", approved_limit 450000
```

## Покрытие критериев приёмки спеки

| AC | Проверка | Где |
|---|---|---|
| AC-MILEAGE-01 | testKeepsApproveWhenMileageBelowReviewThreshold | юнит |
| AC-MILEAGE-02 | testKeepsApproveAtReviewThreshold | юнит |
| AC-MILEAGE-03 | testKeepsReviewByLtvAtReviewThreshold | юнит |
| AC-MILEAGE-04 | testDowngradesApproveToReviewAboveReviewThreshold | юнит |
| AC-MILEAGE-05 | testKeepsReviewWhenMileageAboveReviewThreshold | юнит |
| AC-MILEAGE-06 | testKeepsRejectWhenMileageAboveReviewThreshold | юнит |
| AC-MILEAGE-07 | testDowngradesApproveToReviewAtHardCeiling + testAcceptsMileageAtHardCeiling | юнит |
| AC-MILEAGE-08 | testRejectsMileageAboveHardCeiling (юнит, `ValidationException`); HTTP 422 — существующий маппинг `ApplicationController` (строки 58–61, не меняется) | юнит + smoke |
| AC-MILEAGE-09 | testRejectsMissingMileage (юнит); HTTP 422 — как в AC-08 | юнит |
| AC-MILEAGE-10 | testRejectsNullMileage (юнит); HTTP 422 — как в AC-08 | юнит |
| AC-MILEAGE-11 | тестом не закрепляем по решению заказчика (интервью, вопрос 4); smoke `mileage:""` → approve | smoke |
| AC-MILEAGE-12 | testKeepsApproveAtReviewThreshold (approved_limit = 450 000) | юнит |
| AC-MILEAGE-13 | testDowngradesApproveToReviewAboveReviewThreshold (approved_limit = 0) | юнит |
| AC-MILEAGE-14 | testDowngradedReviewHasSameShapeAsReviewByLtv (равенство ключей, нет `reason`); контроллер `/api/ltv` отдаёт фиксированные 4 поля (строки 64–69) и не меняется | юнит |
| AC-MILEAGE-15 | testReviewThresholdDoesNotDependOnVehicleAge | юнит |
| AC-MILEAGE-16 | testMileageDoesNotChangeLtv | юнит |

Все 16 критериев приёмки закрыты; требования REQ-MILEAGE-01…10 покрываются
перечисленными AC (трассировка REQ ↔ AC — в спеке, разделы 2–3).

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
   сумме (бизнес-ошибка, REQ-MILEAGE-07). Расширять `DecisionEngine::decide()` не
   нужно — code_map прямо рекомендует уровень оркестратора.
4. **Пустая строка пробега не закреплена тестом**: по решению заказчика (интервью,
   вопрос 4) поведение `'' → 0 км` не меняем и тестом не закрепляем. Остаточный риск:
   если кто-то позже «починит» нормализацию `(int)''` в `ApplicationValidator`,
   AC-MILEAGE-11 сломается молча — единственная страховка smoke. Смягчение: из формы
   фронтенда `''` не отправляется (поле `required`, дефолт 84 000, коэрция `Number()`
   в `frontend/app.js`) — кейс чисто API-уровневый. Закрепление тестом — отдельное
   решение с заказчиком.
5. **Ответ API не объясняет причину review** — теперь требование спеки (REQ-MILEAGE-08,
   AC-MILEAGE-14): пониженное из-за пробега review неотличимо от review по LTV ни для
   клиента, ни для оператора. Закреплено тестом равенства форм ответов; поле `reason` —
   отдельное изменение контракта, в задачу не входит.
6. **Рассинхрон документации**: `code_map.md` после реализации описывает несуществующее
   состояние («пробег на решение не влияет», черновик правила). Без шага 8 следующие
   планировщики будут читать ложные факты.
7. **Существующие тесты и отсутствие feature-тестов**: существующие не затронуты
   (дефолтные пробеги 84 000/96 000 ниже порога; `DecisionEngineTest`, `LtvCalculatorTest`,
   `VinValidatorTest` к пробегу отношения не имеют). Feature-тестов в репозитории нет
   (`tests/Feature/` пуст) — маппинг `ValidationException` → HTTP 422 покрыт только
   юнит-уровнем + smoke вручную.
8. **Данные**: seed-значения 20 000–296 000 км ниже порога; схема БД не меняется
   (`vehicles.mileage_km INT UNSIGNED`), миграций и пересева не требуется.

Двусмысленности прошлой версии плана («перекрывает ли правило reject», «включительность
границы 400 000») сняты интервью (вопросы 1–2) и закреплены тестами AC-MILEAGE-02…06 —
из рисков удалены.

## Не входит

- Правки `ApplicationValidator` (включая проверку `max_mileage_km` и её текст),
  `DecisionEngine`, `LtvCalculator`, `VehicleAge`, `VinValidator`, `ValidationException`.
- Изменение контракта ответа HTTP (поле `reason` и т.п.) и фронтенда (подсказки в форме,
  пояснение причин в результатах) — REQ-MILEAGE-08.
- Дифференциация порога 400 000 по возрасту или стоимости авто — REQ-MILEAGE-09
  фиксирует единый порог; уточнения потребовали бы правки спеки.
- Влияние пробега на расчёт LTV и его пороги (`approve_max`, `review_max`) — REQ-MILEAGE-10.
- Правки БД: `schema.sql`, `seed.sql`, миграции, `make seed`.
- Новые Feature/HTTP-тесты.
- Задача LOAN-12 (лимит по `ltv_by_age`). Побочный плюс: инъекция `$rules` в
  `AssessmentService` готовит для неё почву.
- Влияние пробега на `approved_limit` сверх обнуления лимита при понижении до review.

## Вопросы заказчику — закрыты интервью

Раздел «вопросы, на которые без заказчика не ответить» из прошлой версии закрыт:
все шесть вопросов заданы в режиме Grill Me (`docs/intent/grill_MILEAGE.md`),
ответы совпали с дефолтами плана (grill, строки 51–53) и легли в спеку.

| # | Вопрос | Ответ заказчика | Покрыто в спеке | Как в плане |
|---|---|---|---|---|
| 1 | Только понижение approve → review или перекрытие любого решения? | только approve → review; review/reject по LTV не меняются | REQ-MILEAGE-02, REQ-MILEAGE-03 | условие `$decision === APPROVE` в шаге 2 |
| 2 | Ровно 400 000 км — approve или review? | ещё approve, граница включительная (≤ 400 000) | REQ-MILEAGE-01, AC-MILEAGE-02 | строгое `>` в шаге 2; тесты AC-02/03 |
| 3 | Пробег не указан (нет ключа / null) — оставить 422? | оставить 422, валидатор не трогаем | REQ-MILEAGE-05 | без правок `ApplicationValidator`; тесты AC-09/10 |
| 4 | Пустая строка `''` → 0 км — оставить? | оставить; не меняем и **тестом не закрепляем** | REQ-MILEAGE-06, AC-MILEAGE-11 | теста нет, проверка smoke |
| 5 | Причина review в ответе API — нужна? | без причины, контракт не трогаем | REQ-MILEAGE-08, AC-MILEAGE-14 | контракт не меняется, тест равенства форм |
| 6 | Порог единый или зависит от возраста/стоимости? | единый, 400 000 из `rules.php` | REQ-MILEAGE-09, AC-MILEAGE-15 | один ключ `max_mileage_review_km`; тест AC-15 |

Открытых вопросов нет: план готов к реализации.
