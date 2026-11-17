<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Helper to safely parse datetime into Asia/Jakarta (or app default timezone) string
     */
    protected function parseDateTime($value)
    {
        if (empty($value)) {
            return null;
        }

        $tz = config('app.timezone', 'Asia/Jakarta');

        try {
            if (is_string($value) && (str_ends_with($value, 'Z') || preg_match('/[+-]\d{2}:\d{2}$/', $value))) {
                return Carbon::parse($value)->setTimezone($tz)->format('Y-m-d H:i:s');
            }
            return Carbon::parse(str_replace('T', ' ', $value), $tz)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get active announcements for public portal (Home / Landing Page)
     * Automatically purges announcements that have passed their expiration / end time.
     */
    public function publicActive()
    {
        // 1. Otomatis bersihkan pengumuman yang sudah kedaluwarsa
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
                    'start_time' => $item->start_time ? $item->start_time->format('Y-m-d H:i:s') : null,
                    'end_time' => $item->end_time ? $item->end_time->format('Y-m-d H:i:s') : null,
                    'expires_at' => $item->expires_at ? $item->expires_at->format('Y-m-d H:i:s') : null,
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

        $announcements = Announcement::orderBy('id', 'desc')->get()->map(function ($item) {
            return [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'message' => $item->message,
                'start_time' => $item->start_time ? $item->start_time->format('Y-m-d H:i:s') : null,
                'end_time' => $item->end_time ? $item->end_time->format('Y-m-d H:i:s') : null,
                'expires_at' => $item->expires_at ? $item->expires_at->format('Y-m-d H:i:s') : null,
                'is_active' => (bool)$item->is_active,
                'dismissible' => (bool)$item->dismissible,
                'created_at' => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : null,
                'updated_at' => $item->updated_at ? $item->updated_at->format('Y-m-d H:i:s') : null,
            ];
        });

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
            'start_time' => 'nullable',
            'end_time' => 'nullable',
            'expires_at' => 'nullable',
            'is_active' => 'boolean',
            'dismissible' => 'boolean',
        ]);

        $validated['start_time'] = $this->parseDateTime($request->input('start_time'));
        $validated['end_time'] = $this->parseDateTime($request->input('end_time'));
        $validated['expires_at'] = $this->parseDateTime($request->input('expires_at'));

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
            'start_time' => 'nullable',
            'end_time' => 'nullable',
            'expires_at' => 'nullable',
            'is_active' => 'boolean',
            'dismissible' => 'boolean',
        ]);

        $validated['start_time'] = $this->parseDateTime($request->input('start_time'));
        $validated['end_time'] = $this->parseDateTime($request->input('end_time'));
        $validated['expires_at'] = $this->parseDateTime($request->input('expires_at'));

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
