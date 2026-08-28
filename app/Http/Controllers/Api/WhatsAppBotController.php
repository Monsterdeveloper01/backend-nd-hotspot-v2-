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
        $query = trim($request->input('q', ''));

        if (empty($query)) {
            return response()->json(['found' => false, 'message' => 'Query is empty'], 400);
        }

        // 1. Try to find by voucher code
        $voucher = Voucher::with('plan')
            ->where('code', $query)
            ->orWhere('code', strtoupper($query))
            ->first();

        if ($voucher) {
            $mikrotikData = null;
            $isOnline = false;

            try {
                $mikrotikData = $this->mikrotik->getHotspotUserDetailed($voucher->code);
                $isOnline = $mikrotikData ? ($mikrotikData['is_online'] ?? false) : false;
            } catch (\Exception $e) {
                \Log::warning("WA Bot: Mikrotik check failed for voucher {$voucher->code}: " . $e->getMessage());
            }

            return response()->json([
                'found' => true,
                'type' => 'voucher',
                'voucher' => [
                    'code' => $voucher->code,
                    'plan_name' => $voucher->plan->name ?? 'Unknown',
                    'status' => $voucher->status,
                    'price' => $voucher->price,
                    'customer_phone' => $voucher->customer_phone,
                    'mac_address' => $voucher->mac_address,
                    'is_online' => $isOnline,
                    'uptime' => $mikrotikData['uptime'] ?? null,
                    'limit_uptime' => $mikrotikData['limit_uptime'] ?? null,
                    'profile' => $mikrotikData['profile'] ?? null,
                    'used_at' => $voucher->used_at,
                    'expires_at' => $voucher->expires_at,
                ],
            ]);
        }

        // 2. Try to find by customer name or phone
        $customer = Customer::where('name', 'LIKE', $query)
            ->orWhere('name', 'LIKE', "%{$query}%")
            ->orWhere('whatsapp', 'LIKE', "%{$query}%")
            ->first();

        if ($customer) {
            $mikrotikStatus = null;

            try {
                $mikrotikData = $this->mikrotik->getHotspotUserDetailed($customer->name);
                if ($mikrotikData) {
                    $mikrotikStatus = $mikrotikData['is_online'] ? 'online' : 'offline';
                }
            } catch (\Exception $e) {
                \Log::warning("WA Bot: Mikrotik check failed for customer {$customer->name}: " . $e->getMessage());
            }

            return response()->json([
                'found' => true,
                'type' => 'customer',
                'customer' => [
                    'name' => $customer->name,
                    'whatsapp' => $customer->whatsapp,
                    'billing_amount' => $customer->billing_amount,
                    'due_date' => $customer->due_date ? $customer->due_date->format('d/m/Y') : null,
                    'status_bayar' => $customer->status_bayar,
                    'is_isolated' => $customer->is_isolated,
                    'mikrotik_status' => $mikrotikStatus,
                ],
            ]);
        }

        // 3. Not found
        return response()->json(['found' => false, 'message' => 'Data tidak ditemukan'], 404);
    }

    /**
     * Store a complaint from WA Gateway
     * Called by WA Gateway: POST /api/wa/complaint
     */
    public function storeComplaint(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'raw_message' => 'required|string',
            'ai_summary' => 'nullable|string',
        ]);

        $complaint = Complaint::create([
            'phone_number' => $request->phone_number,
            'raw_message' => $request->raw_message,
            'ai_summary' => $request->ai_summary ?? $request->raw_message,
            'status' => 'new',
        ]);

        return response()->json(['success' => true, 'complaint' => $complaint]);
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
