<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Voucher;
use App\Models\Customer;
use App\Services\MikrotikService;
use Illuminate\Http\Request;

class WhatsAppBotController extends Controller
{
    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Check status of a voucher or customer
     * Called by WA Gateway: GET /api/wa/check-status?q=...
     */
    public function checkStatus(Request $request)
    {
        // WA Bot dimatikan secara penuh sesuai permintaan admin (tidak ada cek voucher atau info pelanggan via bot)
        return response()->json([
            'found' => false,
            'disabled' => true,
            'message' => 'Layanan WA Bot dinonaktifkan.'
        ], 403);
    }

    /**
     * Store a complaint from WA Gateway
     * Called by WA Gateway: POST /api/wa/complaint
     */
    public function storeComplaint(Request $request)
    {
        return response()->json([
            'success' => false,
            'disabled' => true,
            'message' => 'Layanan WA Bot dinonaktifkan.'
        ], 403);
    }

    /**
     * Get latest complaints for admin bell icon
     * GET /api/admin/complaints?limit=5
     */
    public function getComplaints(Request $request)
    {
        $limit = $request->input('limit', 5);
        
        $complaints = Complaint::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $unreadCount = Complaint::where('status', 'new')->count();

        return response()->json([
            'complaints' => $complaints,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark a complaint as read
     * PATCH /api/admin/complaints/{id}/read
     */
    public function markAsRead($id)
    {
        $complaint = Complaint::findOrFail($id);
        $complaint->status = 'read';
        $complaint->save();

        return response()->json(['success' => true]);
    }
}
