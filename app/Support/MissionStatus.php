<?php

namespace App\Support;

final class MissionStatus
{
    public const PENDING = 'Pendiente';
    public const SCHEDULED = 'Programada';
    public const ACTIVE = 'Activa';
    public const IN_PROGRESS = 'En Curso';
    public const REPORTED = 'Reportada';
    public const FINISHED = 'Finalizada';
    public const CANCELLED = 'Cancelada';
    public const UNKNOWN = 'Desconocido';

    public static function normalize(?string $status): string
    {
        $key = strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $status) ?? ''));

        return match ($key) {
            'ACTIVA' => self::ACTIVE,
            'EN CURSO' => self::IN_PROGRESS,
            'PROGRAMADA' => self::SCHEDULED,
            'REPORTADA' => self::REPORTED,
            'COMPLETADA', 'TERMINADA', 'FINALIZADA' => self::FINISHED,
            'CANCELADA' => self::CANCELLED,
            'PENDIENTE' => self::PENDING,
            default => self::UNKNOWN,
        };
    }

    public static function acceptsOperationalEntries(?string $status): bool
    {
        return in_array(self::normalize($status), [
            self::PENDING,
            self::SCHEDULED,
            self::ACTIVE,
            self::IN_PROGRESS,
            self::REPORTED,
        ], true);
    }
}
