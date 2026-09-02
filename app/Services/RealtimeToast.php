<?php

namespace App\Services;

use App\Events\ToastNotification;
use App\Models\ToastNotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RealtimeToast
{
    public static function toRoles(array $roles, array $payload, ?int $excludeUserId = null): void
    {
        $normalizedRoles = collect($roles)
            ->map(fn ($role) => strtoupper(trim((string) $role)))
            ->filter()
            ->unique()
            ->values();

        if ($normalizedRoles->isEmpty()) {
            return;
        }

        $placeholders = $normalizedRoles->map(fn () => '?')->implode(',');
        $userIds = User::query()
            ->whereRaw("UPPER(TRIM(rol)) IN ({$placeholders})", $normalizedRoles->all())
            ->pluck('id');

        self::toUsers($userIds, $payload, $excludeUserId);
    }

    public static function toUsers(iterable $userIds, array $payload, ?int $excludeUserId = null): void
    {
        $recipients = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id !== $excludeUserId)
            ->unique()
            ->values()
            ->all();

        if (empty($recipients)) {
            return;
        }

        $payload = array_merge([
            'icon' => 'info',
            'title' => 'Nueva notificación',
            'text' => '',
            'url' => null,
            'key' => null,
            'type' => ToastNotificationLog::TYPE_GENERIC,
            'audience' => 'private',
            'actor_user_id' => null,
        ], $payload);

        self::persist($recipients, $payload);

        try {
            broadcast(new ToastNotification($recipients, $payload));
        } catch (\Throwable $exception) {
            Log::warning('No se pudo emitir un toast en tiempo real.', [
                'error' => $exception->getMessage(),
                'recipients' => $recipients,
                'key' => $payload['key'],
            ]);
        }
    }

    private static function persist(array $recipients, array $payload): void
    {
        try {
            if (! Schema::hasTable('toast_notification_logs')) {
                return;
            }

            foreach ($recipients as $recipientId) {
                $data = [
                    'type' => $payload['type'] ?? ToastNotificationLog::TYPE_GENERIC,
                    'icon' => $payload['icon'] ?? 'info',
                    'title' => $payload['title'] ?? 'Nueva notificación',
                    'text' => $payload['text'] ?? '',
                    'url' => $payload['url'] ?? null,
                    'key' => $payload['key'] ?? null,
                    'recipient_user_id' => $recipientId,
                    'actor_user_id' => $payload['actor_user_id'] ?? null,
                    'audience' => $payload['audience'] ?? 'private',
                    'read_at' => null,
                ];

                if (! empty($data['key'])) {
                    ToastNotificationLog::query()->updateOrCreate(
                        [
                            'recipient_user_id' => $recipientId,
                            'key' => $data['key'],
                        ],
                        $data,
                    );

                    continue;
                }

                ToastNotificationLog::query()->create($data);
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo guardar el historial de toast.', [
                'error' => $exception->getMessage(),
                'recipients' => $recipients,
                'key' => $payload['key'] ?? null,
            ]);
        }
    }
}
