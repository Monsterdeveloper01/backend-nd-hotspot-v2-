<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemIncident;
use App\Services\WhatsAppService;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class IncidentController extends Controller
{
    /**
     * Ambil daftar insiden dengan pagination, filter, dan statistik.
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'unresolved'); // unresolved, resolved, all
        $category = $request->input('category', 'all');
        $severity = $request->input('severity', 'all');
        $search = $request->input('search');

        $query = SystemIncident::latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($category !== 'all') {
            $query->where('category', $category);
        }

        if ($severity !== 'all') {
            $query->where('severity', $severity);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%")
                  ->orWhere('technical_details', 'like', "%{$search}%");
            });
        }

        $incidents = $query->paginate(15);

        // Ringkasan Statistik
        $today = Carbon::today()->toDateString();
        $stats = [
            'unresolved_count'      => SystemIncident::where('status', 'unresolved')->count(),
            'critical_count'        => SystemIncident::where('status', 'unresolved')->where('severity', 'critical')->count(),
            'today_count'           => SystemIncident::whereDate('created_at', $today)->count(),
            'whatsapp_failed_count' => SystemIncident::where('status', 'unresolved')->where('category', 'whatsapp')->count(),
            'fraud_count'           => SystemIncident::where('status', 'unresolved')->where('category', 'payment_fraud')->count(),
            'mikrotik_count'        => SystemIncident::where('status', 'unresolved')->where('category', 'mikrotik')->count(),
            'system_count'          => SystemIncident::where('status', 'unresolved')->where('category', 'system')->count(),
        ];

        return response()->json([
            'success'   => true,
            'stats'     => $stats,
            'incidents' => $incidents,
        ]);
    }

    /**
     * Endpoint ringan untuk lonceng notifikasi di Navbar.
     */
    public function summary()
    {
        $unresolvedCount = SystemIncident::where('status', 'unresolved')->count();
        $criticalCount = SystemIncident::where('status', 'unresolved')->where('severity', 'critical')->count();
        $latest = SystemIncident::where('status', 'unresolved')->latest()->take(5)->get();

        return response()->json([
            'success'          => true,
            'unresolved_count' => $unresolvedCount,
            'critical_count'   => $criticalCount,
            'latest'           => $latest,
        ]);
    }

    /**
     * Tandai insiden sebagai selesai (Resolved).
     */
    public function resolve($id)
    {
        $incident = SystemIncident::findOrFail($id);
        $incident->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => auth()->user()?->name ?? 'Admin',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Insiden berhasil ditandai sebagai selesai.',
        ]);
    }

    /**
     * Tandai semua insiden dalam kategori tertentu sebagai selesai.
     */
    public function resolveAll(Request $request)
    {
        $category = $request->input('category');
        $query = SystemIncident::where('status', 'unresolved');

        if (!empty($category) && $category !== 'all') {
            $query->where('category', $category);
        }

        $count = $query->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => auth()->user()?->name ?? 'Admin',
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$count} insiden berhasil diselesaikan.",
        ]);
    }

    /**
     * Eksekusi aksi perbaikan ulang (Retry Action).
     */
    public function retry($id, WhatsAppService $whatsapp, MikrotikService $mikrotik)
    {
        $incident = SystemIncident::findOrFail($id);
        $payload = $incident->payload ?? [];

        // 1. Aksi Kirim Ulang WhatsApp
        if ($incident->action_type === 'retry_whatsapp') {
            $phone = $payload['phone'] ?? null;
            $message = $payload['message'] ?? null;

            if (empty($phone) || empty($message)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data nomor telepon atau pesan tidak lengkap pada log ini.',
                ], 422);
            }

            $sent = $whatsapp->sendMessage($phone, $message, 'Retry via Dashboard');

            if ($sent) {
                $incident->update([
                    'status'      => 'retried',
                    'resolved_at' => now(),
                    'resolved_by' => auth()->user()?->name ?? 'Admin',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Pesan WhatsApp berhasil dikirim ulang ke {$phone}!",
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengirim ulang. Pastikan gateway WhatsApp di port 5000 aktif.',
                ], 500);
            }
        }

        // 2. Aksi Isolir Ulang MikroTik
        if ($incident->action_type === 'manual_isolate') {
            $customerName = $payload['customer_name'] ?? null;

            if (empty($customerName)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama customer tidak ditemukan di payload.',
                ], 422);
            }

            $disabled = $mikrotik->setUserStatus($customerName, false);
            if ($disabled) {
                $mikrotik->clearUserActiveSessions($customerName);
                $mikrotik->clearUserCookies($customerName);

                $incident->update([
                    'status'      => 'resolved',
                    'resolved_at' => now(),
                    'resolved_by' => auth()->user()?->name ?? 'Admin',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Akun {$customerName} berhasil diisolir di MikroTik!",
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => "Gagal terhubung ke router MikroTik saat mencoba isolir {$customerName}.",
                ], 500);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Aksi retry tidak didukung untuk tipe insiden ini.',
        ], 400);
    }

    /**
     * Hapus log insiden.
     */
    public function destroy($id)
    {
        $incident = SystemIncident::findOrFail($id);
        $incident->delete();

        return response()->json([
            'success' => true,
            'message' => 'Log insiden berhasil dihapus.',
        ]);
    }
}