<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkNotificationRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Info(
 *     title="Notification Service API",
 *     version="1.0.0",
 *     description="Микросервис массовой рассылки уведомлений (SMS/Email)"
 * )
 * @OA\Server(url="/api/v1", description="API v1")
 * @OA\SecurityScheme(
 *     securityScheme="ApiKey",
 *     type="apiKey",
 *     in="header",
 *     name="X-Api-Key"
 * )
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    /**
     * @OA\Post(
     *     path="/notifications/bulk",
     *     summary="Запустить массовую рассылку уведомлений",
     *     tags={"Notifications"},
     *     security={{"ApiKey":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"channel","type","message","idempotency_key","recipient_ids"},
     *             @OA\Property(property="channel", type="string", enum={"sms","email"}, example="sms"),
     *             @OA\Property(property="type", type="string", enum={"transactional","marketing"}, example="transactional"),
     *             @OA\Property(property="message", type="string", example="Ваш код доступа: 1234"),
     *             @OA\Property(property="idempotency_key", type="string", example="order-svc-uuid-abc123"),
     *             @OA\Property(property="recipient_ids", type="array", @OA\Items(type="integer"), example={101,102,103})
     *         )
     *     ),
     *     @OA\Response(
     *         response=202,
     *         description="Рассылка принята в обработку",
     *         @OA\JsonContent(
     *             @OA\Property(property="batch_id", type="string", format="uuid"),
     *             @OA\Property(property="accepted_count", type="integer"),
     *             @OA\Property(property="status", type="string", example="processing")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function bulk(BulkNotificationRequest $request): JsonResponse
    {
        $batch = $this->service->dispatchBulk(
            channel: NotificationChannel::from($request->input('channel')),
            type: NotificationType::from($request->input('type')),
            message: $request->input('message'),
            idempotencyKey: $request->input('idempotency_key'),
            recipientIds: $request->input('recipient_ids'),
        );

        return response()->json([
            'batch_id'       => $batch->id,
            'accepted_count' => $batch->total_count,
            'status'         => $batch->status->value,
        ], 202);
    }

    /**
     * @OA\Get(
     *     path="/subscribers/{subscriber_id}/notifications",
     *     summary="Получить историю уведомлений подписчика",
     *     tags={"Subscribers"},
     *     security={{"ApiKey":{}}},
     *     @OA\Parameter(name="subscriber_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1, minimum=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=50, minimum=1, maximum=100)),
     *     @OA\Response(
     *         response=200,
     *         description="Список уведомлений с пагинацией",
     *         @OA\JsonContent(
     *             @OA\Property(property="subscriber_id", type="integer", example=101),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="string", format="uuid"),
     *                 @OA\Property(property="batch_id", type="string", format="uuid"),
     *                 @OA\Property(property="channel", type="string", enum={"sms","email"}),
     *                 @OA\Property(property="type", type="string", enum={"transactional","marketing"}),
     *                 @OA\Property(property="message", type="string"),
     *                 @OA\Property(property="status", type="string", enum={"queued","sent","delivered","discarded"}),
     *                 @OA\Property(property="retry_count", type="integer"),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="total", type="integer"),
     *                 @OA\Property(property="last_page", type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function subscriberNotifications(Request $request, int $subscriberId): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 50), 100));

        $paginator = $this->service->getSubscriberNotifications($subscriberId, $perPage);

        return response()->json([
            'subscriber_id' => $subscriberId,
            'data' => array_map(fn ($n) => [
                'id'          => $n->id,
                'batch_id'    => $n->batch_id,
                'channel'     => $n->channel->value,
                'type'        => $n->type->value,
                'message'     => $n->message,
                'status'      => $n->status->value,
                'retry_count' => $n->retry_count,
                'created_at'  => $n->created_at?->toIso8601String(),
                'updated_at'  => $n->updated_at?->toIso8601String(),
            ], $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }
}
