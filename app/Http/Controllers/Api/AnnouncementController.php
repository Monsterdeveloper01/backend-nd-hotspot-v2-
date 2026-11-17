<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Get active announcements for public portal (Home / Landing Page)
     * Automatically purges announcements that have passed their expiration / end time.
     */
    public function publicActive()
    {
        // 1. Otomatis hapus / bersihkan data yang sudah lewat masa berlaku
        Announcement::purgeExpired();

        // 2. Ambil pengumuman yang masih aktif
        $now = Carbon::now();
        $announcements = Announcement::active()
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($item) use ($now) {
                $start = $item->start_time;
                $end = $item->end_time;

                $isOngoing = false;
                $isUpcoming = false;

                if ($start) {
                    if ($start <= $now && (!$end || $end >= $now)) {
                        $isOngoing = true;
                    } elseif ($start > $now) {
                        $isUpcoming = true;
                    }
                }

                $timeRange = null;
                if ($start && $end) {
                    $startFormatted = Carbon::parse($start)->locale('id')->isoFormat('dddd, D MMM Y HH:mm');
                    $endFormatted = Carbon::parse($end)->locale('id')->isoFormat('HH:mm');
                    $timeRange = "{$startFormatted} - {$endFormatted} WIB";
                } elseif ($start) {
                    $timeRange = Carbon::parse($start)->locale('id')->isoFormat('dddd, D MMM Y HH:mm') . ' WIB';
                }

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'type' => $item->type,
                    'message' => $item->message,
                    'start_time' => $item->start_time ? $item->start_time->toIso8601String() : null,
                    'end_time' => $item->end_time ? $item->end_time->toIso8601String() : null,
                    'expires_at' => $item->expires_at ? $item->expires_at->toIso8601String() : null,
                    'is_ongoing' => $isOngoing,
                    'is_upcoming' => $isUpcoming,
                    'time_range_formatted' => $timeRange,
                    'dismissible' => (bool)$item->dismissible,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $announcements,
        ]);
    }

    /**
     * List all announcements for Admin
     */
    public function index()
    {
        // Bersihkan yang sudah kedaluwarsa
        $purgedCount = Announcement::purgeExpired();

        $announcements = Announcement::orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $announcements,
            'purged_count' => $purgedCount,
        ]);
    }

    /**
     * Store new announcement
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:maintenance,warning,info,promo',
            'message' => 'required|string',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date|after_or_equal:start_time',
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',
            'dismissible' => 'boolean',
        ]);

        // Jika expires_at tidak diisi tetapi end_time ada, otomatis gunakan end_time sebagai batas kedaluwarsa
        if (empty($validated['expires_at']) && !empty($validated['end_time'])) {
            $validated['expires_at'] = $validated['end_time'];
        }

        $announcement = Announcement::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Informasi pengumuman berhasil disimpan.',
            'data' => $announcement,
        ], 201);
    }

    /**
     * Update existing announcement
     */
    public function update(Request $request, $id)
    {
        $announcement = Announcement::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:maintenance,warning,info,promo',
            'message' => 'required|string',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',
            'dismissible' => 'boolean',
        ]);

        if (empty($validated['expires_at']) && !empty($validated['end_time'])) {
            $validated['expires_at'] = $validated['end_time'];
        }

        $announcement->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Informasi pengumuman berhasil diperbarui.',
            'data' => $announcement,
        ]);
    }

    /**
     * Toggle active status
     */
    public function toggle($id)
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->is_active = !$announcement->is_active;
        $announcement->save();

        return response()->json([
            'success' => true,
            'message' => 'Status pengumuman berhasil diubah.',
            'is_active' => $announcement->is_active,
        ]);
    }

    /**
     * Delete announcement
     */
    public function destroy($id)
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->delete();

        return response()->json([
            'success' => true,
            'message' => 'Informasi pengumuman berhasil dihapus.',
        ]);
    }
}
