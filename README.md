# Notification Service

[![GitHub](https://img.shields.io/badge/GitHub-Joker1796%2Fnotification--service-blue?logo=github)](https://github.com/Joker1796/notification-service)

Микросервис массовой рассылки уведомлений (SMS/Email) с приоритезацией трафика, отслеживанием статусов и гарантией доставки.

## Технологический стек

- **PHP 8.2 + Laravel 13** — приложение
- **PostgreSQL 16** — хранение уведомлений и батчей
- **RabbitMQ 3.13** — очереди сообщений с приоритетами
- **Redis 7** — дедубликация (idempotency keys) и кэш
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

Worker (queue:work rabbitmq_high,rabbitmq_low --queue=notifications.transactional,notifications.marketing)
  │
  ├─ Exactly-once guard: статус != queued? → пропустить
  ├─ Обновить статус → sent
  ├─ Вызвать MockSmsProvider / MockEmailProvider
  │    ├── success → статус → delivered
  │    └── TemporaryProviderException → retry_count++ → статус → queued → retry
  └─ После 3 попыток: статус → discarded
```

### Приоритизация трафика

Worker слушает два соединения в порядке приоритета:
```
rabbitmq_high (notifications.transactional) → rabbitmq_low (notifications.marketing)
```
Laravel дренирует transactional-очередь полностью перед обработкой marketing. Транзакционные сообщения (коды доступа, срочные уведомления) никогда не ждут в очереди за маркетинговыми рассылками.

### Гарантии доставки

- **At-least-once**: RabbitMQ сохраняет сообщения персистентно; при падении воркера сообщения перечитываются
- **Exactly-once на уровне бизнес-логики**: Job проверяет статус уведомления перед обработкой — повторная доставка из RabbitMQ игнорируется если статус ≠ queued
- **Retry**: 3 автоматические попытки с паузой 30 секунд
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

# 2. Запустить все сервисы одной командой
docker compose up -d

# 3. Дождаться готовности (примерно 30 секунд на первый старт)
docker compose logs -f app
```

Сервис автоматически выполняет миграции при старте. После появления `ready to handle connections` в логах — сервис готов к работе.

### Аутентификация

Все API-запросы требуют заголовок `X-Api-Key`. Значение ключа задаётся в переменной окружения `API_KEY` (см. `.env`).

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
http://localhost:15672
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
| `idempotency_key` | string (max 255) | Уникальный ключ для дедубликации |
| `recipient_ids` | array of integers | Массив ID подписчиков (1–10000) |

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
| `sent` | Передано провайдеру |
| `delivered` | Подтверждено провайдером |
| `discarded` | Ошибка, исчерпаны попытки |

## Запуск тестов

```bash
# Внутри Docker (рекомендуется)
docker compose exec app php artisan test --testsuite=Integration --testdox

# Локально (требуются PostgreSQL и Redis)
php artisan test --testsuite=Integration --testdox
```

### Покрытые сценарии

1. ✅ Создание рассылки → 3 уведомления в статусе `queued`
2. ✅ Воркер обрабатывает сообщение → статус `delivered`
3. ✅ Ошибка провайдера → retry → после 3 попыток → `discarded`
4. ✅ Дублирующий запрос с тем же `idempotency_key` → один `batch_id`
5. ✅ Транзакционные → очередь `notifications.transactional`
6. ✅ Маркетинговые → очередь `notifications.marketing`
7. ✅ GET подписчика → корректный список уведомлений разных статусов
8. ✅ Exactly-once guard: уже обработанное уведомление не обрабатывается повторно
