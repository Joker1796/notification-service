# Notification Service

[![GitHub](https://img.shields.io/badge/GitHub-Joker1796%2Fnotification--service-blue?logo=github)](https://github.com/Joker1796/notification-service)

Микросервис массовой рассылки уведомлений (SMS/Email) с приоритезацией трафика, отслеживанием статусов и гарантией доставки.

## Технологический стек

- **PHP 8.3 + Laravel 13** — приложение
- **PostgreSQL 16** — хранение уведомлений и батчей
- **RabbitMQ 3.13** — очереди сообщений с приоритетами
- **Redis 7** — дедупликация (idempotency keys) и кэш
- **Docker + Docker Compose** — оркестрация

## Архитектура

```
Клиент
  │
  ▼
POST /api/v1/notifications/bulk
  │
  ├─ Проверка idempotency key (Redis) ─── дубликат? → вернуть существующий batch_id
  │
  ├─ Создать NotificationBatch в PostgreSQL
  ├─ Создать Notification записи (статус: queued)
  ├─ Диспетчировать ProcessNotificationJob:
  │    ├── transactional → rabbitmq_high → очередь notifications.transactional
  │    └── marketing     → rabbitmq_low  → очередь notifications.marketing
  └─ Сохранить idempotency key в Redis (TTL 24ч)

worker_high  (выделенный воркер для rabbitmq_high)
  │
  ├─ Exactly-once guard: UPDATE WHERE status='queued' → если не захвачено → пропустить
  ├─ Обновить статус → sent
  ├─ Вызвать MockSmsProvider / MockEmailProvider
  │    ├── success → статус → delivered
  │    └── exception → retry_count++ → статус → queued → retry (30 / 60 / 120 с)
  └─ После 3 попыток: статус → discarded

worker_low  (выделенный воркер для rabbitmq_low, аналогично)

scheduler  (каждые 5 минут)
  ├─ Phase 1: уведомления stuck в 'sent' > 5 мин → сброс в queued
  └─ Phase 2: уведомления в 'queued' без job > 5 мин → повторный dispatch
```

### Приоритизация трафика

Два выделенных воркера обслуживают разные соединения:

```
worker_high → rabbitmq_high → notifications.transactional  (коды доступа, срочные)
worker_low  → rabbitmq_low  → notifications.marketing      (рассылки, акции)
```

Транзакционные уведомления обрабатываются своим воркером независимо от нагрузки маркетинговой очереди — задержки в одной очереди не влияют на другую.

### Гарантии доставки

- **At-least-once**: RabbitMQ хранит сообщения персистентно; при падении воркера сообщения перечитываются
- **Exactly-once на уровне бизнес-логики**: Job атомарно захватывает уведомление через `UPDATE WHERE status='queued'` — повторная доставка из RabbitMQ игнорируется
- **Retry с экспоненциальным бэкоффом**: 3 автоматические попытки с паузами 30 / 60 / 120 секунд
- **Crash recovery**: планировщик каждые 5 минут восстанавливает уведомления, потерянные при падении воркера
- **Идемпотентность API**: повторный запрос с тем же `idempotency_key` возвращает тот же `batch_id` без дублирования записей

## Быстрый старт

### Требования

- Docker 24+
- Docker Compose v2

### Запуск

```bash
# 1. Клонировать репозиторий
git clone <repo-url>
cd notification-service

# 2. Скопировать конфигурацию и задать API-ключ
cp .env.example .env
# Отредактировать .env: установить уникальный API_KEY

# 3. Запустить все сервисы одной командой
docker compose up -d

# 4. Дождаться готовности (примерно 30 секунд на первый старт)
docker compose logs -f app
```

Сервис автоматически выполняет миграции при старте. После появления `ready to handle connections` в логах — сервис готов к работе.

### Аутентификация

Все API-запросы требуют заголовок `X-Api-Key`. Значение ключа задаётся в переменной окружения `API_KEY` в файле `.env`.

### Проверка работы

```bash
# Получить API-ключ из .env
API_KEY=$(grep '^API_KEY=' .env | cut -d= -f2)

# Отправить транзакционное уведомление (высокий приоритет)
curl -X POST http://localhost:8080/api/v1/notifications/bulk \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: $API_KEY" \
  -d '{
    "channel": "sms",
    "type": "transactional",
    "message": "Ваш код доступа: 1234",
    "idempotency_key": "order-svc-001",
    "recipient_ids": [101, 102, 103]
  }'

# Получить статус уведомлений подписчика (с пагинацией)
curl -H "X-Api-Key: $API_KEY" \
  "http://localhost:8080/api/v1/subscribers/101/notifications?page=1&per_page=20"

# Повторить запрос с тем же idempotency_key — вернётся тот же batch_id
curl -X POST http://localhost:8080/api/v1/notifications/bulk \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: $API_KEY" \
  -d '{
    "channel": "sms",
    "type": "transactional",
    "message": "Ваш код доступа: 1234",
    "idempotency_key": "order-svc-001",
    "recipient_ids": [101, 102, 103]
  }'
```

### Swagger UI

Интерактивная документация API доступна по адресу:

```
http://localhost:8080/api/documentation
```

### RabbitMQ Management UI

```
http://localhost:15672  (доступен только с localhost)
Login: app / secret
```

### Остановка

```bash
docker compose down
# Удалить volumes (очистить данные)
docker compose down -v
```

## API Reference

### POST /api/v1/notifications/bulk

Запустить массовую рассылку уведомлений.

**Request Body:**

| Поле | Тип | Описание |
|------|-----|----------|
| `channel` | `sms` \| `email` | Канал доставки |
| `type` | `transactional` \| `marketing` | Приоритет (transactional — выше) |
| `message` | string (max 1000) | Текст сообщения |
| `idempotency_key` | string (max 255) | Уникальный ключ для дедупликации |
| `recipient_ids` | array of integers | Массив уникальных ID подписчиков (1–10 000) |

**Response 202:**
```json
{
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "accepted_count": 3,
  "status": "processing"
}
```

**Response 422** — ошибка валидации.  
**Response 401** — отсутствует или неверный `X-Api-Key`.

---

### GET /api/v1/subscribers/{subscriber_id}/notifications

Получить историю и текущий статус уведомлений подписчика с пагинацией.

**Query parameters:** `page` (default: 1), `per_page` (default: 50, max: 100).

**Response 200:**
```json
{
  "subscriber_id": 101,
  "data": [
    {
      "id": "uuid",
      "batch_id": "uuid",
      "channel": "sms",
      "type": "transactional",
      "message": "Ваш код доступа: 1234",
      "status": "delivered",
      "retry_count": 0,
      "created_at": "2026-06-09T10:00:00+00:00",
      "updated_at": "2026-06-09T10:00:02+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 50,
    "total": 312,
    "last_page": 7
  }
}
```

**Статусы уведомлений:**

| Статус | Описание |
|--------|----------|
| `queued` | Принято, ожидает отправки |
| `sent` | Передано провайдеру (промежуточный) |
| `delivered` | Подтверждено провайдером |
| `discarded` | Ошибка, исчерпаны попытки |

## Запуск тестов

```bash
# Внутри Docker (рекомендуется)
docker compose exec app php artisan test --testsuite=Integration --testdox

# Локально (требуются PostgreSQL :5433 и Redis :6380, см. phpunit.xml)
php artisan test --testsuite=Integration --testdox
```

### Покрытые сценарии (33 теста)

**Основной поток:**
1. ✅ Создание рассылки → уведомления в статусе `queued`
2. ✅ Воркер обрабатывает сообщение → статус `delivered`
3. ✅ Ошибка провайдера → retry → после 3 попыток → `discarded`
4. ✅ Дублирующий запрос с тем же `idempotency_key` → один `batch_id`
5. ✅ Транзакционные → очередь `notifications.transactional`
6. ✅ Маркетинговые → очередь `notifications.marketing`
7. ✅ GET подписчика → корректный список уведомлений с пагинацией
8. ✅ Exactly-once: уже обработанное уведомление не обрабатывается повторно

**Финализация батча:**
9. ✅ Все уведомления доставлены → batch статус `completed`
10. ✅ Часть уведомлений не доставлена → batch статус `partial_failure`

**Надёжность:**
11. ✅ Не-временное исключение провайдера → статус сброшен в `queued`, retry не теряются
12. ✅ Устаревшая запись Redis (batch удалён) → создаётся новый batch
13. ✅ Дублирующиеся `recipient_ids` дедуплицируются на уровне сервиса

**Восстановление (RecoverStuckNotificationsCommand):**
14. ✅ Phase 1: уведомления stuck в `sent` > N мин → сброс в `queued`
15. ✅ Phase 1: свежие уведомления в `sent` → не затрагиваются
16. ✅ Phase 2: orphaned `queued` уведомления → повторный dispatch
17. ✅ Phase 2: свежие `queued` → не затрагиваются
18. ✅ Phase 2: dispatch в правильную очередь по типу
19. ✅ `--dry-run`: показывает что будет сделано без изменений
20. ✅ `--minutes`: пользовательский порог соблюдается

**Валидация API:**
21. ✅ Невалидный channel → 422
22. ✅ Отсутствующие обязательные поля → 422
23. ✅ Пустой массив `recipient_ids` → 422
24. ✅ Сообщение длиннее 1000 символов → 422
25. ✅ Дубликаты в `recipient_ids` → 422
26. ✅ Отсутствует `X-Api-Key` → 401
27. ✅ Неверный `X-Api-Key` → 401

**Граничные случаи:**
28. ✅ `per_page=0` и `per_page=-N` → clamp до 1
29. ✅ `per_page` > 100 → clamp до 100
30. ✅ Подписчик без уведомлений → пустой `data`, `total: 0`
