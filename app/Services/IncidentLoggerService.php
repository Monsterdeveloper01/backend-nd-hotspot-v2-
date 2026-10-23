<?php

namespace App\Services;

use App\Models\SystemIncident;
use Illuminate\Support\Facades\Log;
use Throwable;

class IncidentLoggerService
{
    /**
     * Catat insiden sistem / fraud baru ke database.
     * Menggunakan fail-safe try-catch agar pencatatan tidak pernah memicu crash baru.
     */
    public static function report(
        string $category,
        string $severity,
        string $title,
        string $message,
        ?array $payload = null,
        ?string $technicalDetails = null,
        ?string $actionType = null
    ): ?SystemIncident {
        try {
            $incident = SystemIncident::create([
                'category'          => $category,
                'severity'          => in_array($severity, ['critical', 'warning', 'info']) ? $severity : 'warning',
                'title'             => mb_substr($title, 0, 190),
                'message'           => $message,
                'technical_details' => $technicalDetails,
                'payload'           => $payload,
                'action_type'       => $actionType,
                'status'            => 'unresolved',
            ]);

            // Jika severity critical, kirim juga notifikasi kilat ke log & Telegram jika ada
            if ($severity === 'critical') {
                Log::critical("[INCIDENT-CRITICAL] {$title}: {$message}", $payload ?? []);

                try {
                    $telegram = app(\App\Services\TelegramService::class);
                    $tgMsg = "🚨 <b>[INCIDENT CRITICAL DETECTED]</b>\n"
                           . "━━━━━━━━━━━━━━━━━━\n"
                           . "📂 Kategori: <b>" . strtoupper($category) . "</b>\n"
                           . "⚠️ Judul: <b>{$title}</b>\n"
                           . "💬 Pesan: {$message}\n"
                           . "⏰ Waktu: " . now()->format('d/m/Y H:i:s') . "\n"
                           . "━━━━━━━━━━━━━━━━━━\n"
                           . "<i>Cek Dashboard Admin untuk detail & penanganan.</i>";
                    $telegram->sendMessage($tgMsg);
                } catch (\Throwable $tgErr) {
                    // Non-blocking jika telegram sedang offline
                }
            } else {
                Log::warning("[INCIDENT-{$severity}] {$title}: {$message}");
            }

            return $incident;
        } catch (Throwable $e) {
            // Fail-safe: catat ke emergency log jika koneksi DB atau tabel bermasalah
            Log::error("[INCIDENT-LOGGER-FAILED] Gagal menyimpan insiden ke DB: " . $e->getMessage(), [
                'category' => $category,
                'title'    => $title,
            ]);
            return null;
        }
    }

    /**
     * Helper khusus kegagalan pengiriman WhatsApp.
     */
    public static function logWhatsAppFailure(string $phone, string $messageContent, string $reason, ?string $context = 'WhatsApp Notification'): ?SystemIncident
    {
        return self::report(
            category: 'whatsapp',
            severity: 'warning',
            title: "Gagal Kirim WA ke {$phone}",
            message: "Pesan '{$context}' tidak berhasil dikirim. Alasan: {$reason}",
            payload: [
                'phone'   => $phone,
                'message' => $messageContent,
                'context' => $context,
            ],
            technicalDetails: $reason,
            actionType: 'retry_whatsapp'
        );
    }

    /**
     * Helper khusus fraud & anomali pembayaran / checkout.
     */
    public static function logPaymentFraud(string $title, string $message, array $details = []): ?SystemIncident
    {
        return self::report(
            category: 'payment_fraud',
            severity: 'critical',
            title: $title,
            message: $message,
            payload: $details,
            technicalDetails: json_encode($details, JSON_PRETTY_PRINT),
            actionType: 'check_payment'
        );
    }

    /**
     * Helper khusus kegagalan operasi MikroTik / isolasi.
     */
    public static function logMikrotikError(string $title, string $message, ?array $details = null): ?SystemIncident
    {
        return self::report(
            category: 'mikrotik',
            severity: 'critical',
            title: $title,
            message: $message,
            payload: $details,
            technicalDetails: json_encode($details, JSON_PRETTY_PRINT),
            actionType: 'manual_isolate'
        );
    }

    /**
     * Helper khusus menangkap Unhandled Exception Global (Error 500 dll).
     */
    public static function logSystemException(Throwable $e, ?string $context = null): ?SystemIncident
    {
        $route = request()?->path() ?? 'CLI / Background';
        $method = request()?->method() ?? 'JOB';
        $ip = request()?->ip() ?? 'CLI';

        return self::report(
            category: 'system',
            severity: 'critical',
            title: "Unhandled Error di [{$method}] {$route}",
            message: $e->getMessage() ?: get_class($e),
            payload: [
                'route'   => $route,
                'method'  => $method,
                'ip'      => $ip,
                'context' => $context,
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ],
            technicalDetails: $e->getTraceAsString(),
            actionType: 'none'
        );
    }
}