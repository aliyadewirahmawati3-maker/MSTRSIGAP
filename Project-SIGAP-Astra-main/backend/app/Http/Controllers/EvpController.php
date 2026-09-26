<?php

namespace App\Http\Controllers;

use App\Models\Intersection;
use App\Models\SystemStatusLog;
use App\Services\HeuristicDecisionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EvpController extends Controller
{
    /**
     * Memicu aktivasi Emergency Vehicle Priority (EVP) pada simpang.
     */
    public function trigger(Request $request, int $id, HeuristicDecisionService $service): JsonResponse
    {
        $intersection = Intersection::find($id);

        if (!$intersection) {
            return response()->json([
                'status' => 'error',
                'message' => "Simpang dengan ID {$id} tidak ditemukan.",
            ], 404);
        }

        $vehicleType = strtolower($request->input('vehicle_type', 'ambulance'));
        if (!in_array($vehicleType, ['ambulance', 'firetruck'])) {
            $vehicleType = 'ambulance';
        }

        $direction = strtoupper($request->input('direction', 'WEST'));
        if (!in_array($direction, ['WEST', 'NORTH', 'EAST', 'SOUTH'])) {
            $direction = 'WEST';
        }

        $vehicleLabel = $vehicleType === 'ambulance' ? 'Ambulans' : 'Mobil Pemadam Kebakaran';
        $directionLabel = match ($direction) {
            'WEST' => 'Barat',
            'NORTH' => 'Utara',
            'EAST' => 'Timur',
            'SOUTH' => 'Selatan',
        };

        // Tentukan fase yang harus dibuka hijau
        $targetPhaseCode = in_array($direction, ['WEST', 'EAST']) ? 'PHASE_EW' : 'PHASE_NS';
        $targetPhaseName = $targetPhaseCode === 'PHASE_EW' ? 'Fase Barat - Timur' : 'Fase Utara - Selatan';

        // Susun payload antrean dengan kendaraan darurat terdeteksi
        $scenario = match ("{$vehicleType}_{$direction}") {
            'ambulance_WEST', 'firetruck_WEST' => 'emergency_west',
            'ambulance_NORTH', 'firetruck_NORTH' => 'emergency_north',
            'ambulance_EAST' => [
                'WEST' => ['counts' => ['motorcycle' => 12, 'car' => 8, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 30],
                'EAST' => ['counts' => ['motorcycle' => 15, 'car' => 10, 'bus' => 1, 'truck' => 0, 'ambulance' => 1, 'firetruck' => 0], 'waiting_seconds' => 35],
                'NORTH' => ['counts' => ['motorcycle' => 10, 'car' => 6, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 20],
                'SOUTH' => ['counts' => ['motorcycle' => 11, 'car' => 5, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 20],
            ],
            default => [
                'WEST' => ['counts' => ['motorcycle' => 10, 'car' => 6, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 20],
                'EAST' => ['counts' => ['motorcycle' => 8, 'car' => 5, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 20],
                'NORTH' => ['counts' => ['motorcycle' => 12, 'car' => 8, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 25],
                'SOUTH' => ['counts' => ['motorcycle' => 16, 'car' => 10, 'bus' => 1, 'truck' => 0, 'ambulance' => 1, 'firetruck' => 0], 'waiting_seconds' => 35],
            ],
        };

        // Jalankan evaluasi heuristik dengan prioritas darurat EVP
        $decision = $service->evaluate($intersection, $scenario);

        $now = Carbon::now();
        $alertMessage = "PERINGATAN EVP: Terdeteksi {$vehicleLabel} dari Arah {$directionLabel}. Koridor prioritas {$targetPhaseName} dibuka aman.";

        $evpData = [
            'is_active' => true,
            'vehicle_type' => $vehicleType,
            'vehicle_label' => $vehicleLabel,
            'direction' => $direction,
            'direction_label' => $directionLabel,
            'target_phase_code' => $targetPhaseCode,
            'target_phase_name' => $targetPhaseName,
            'priority_duration_seconds' => $decision->proposed_duration_seconds,
            'clearance' => [
                'amber_seconds' => 3,
                'all_red_seconds' => 2,
            ],
            'alert_message' => $alertMessage,
            'triggered_at' => $now->toIso8601String(),
            'decision_id' => $decision->id,
        ];

        // Simpan status aktif ke cache selama 3 menit
        Cache::put("sigap_evp_active_{$id}", $evpData, 180);

        // Catat kejadian ke log status sistem
        SystemStatusLog::create([
            'intersection_id' => $id,
            'previous_mode' => 'ATCS_NORMAL',
            'new_mode' => 'EVP_ACTIVE',
            'reason' => $alertMessage,
            'payload' => $evpData,
            'created_at' => $now,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Status darurat EVP berhasil diaktifkan.',
            'data' => $evpData,
        ], 200);
    }

    /**
     * Membatalkan status prioritas darurat secara aman (Safe Cancel).
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $intersection = Intersection::find($id);

        if (!$intersection) {
            return response()->json([
                'status' => 'error',
                'message' => "Simpang dengan ID {$id} tidak ditemukan.",
            ], 404);
        }

        $active = Cache::get("sigap_evp_active_{$id}");
        Cache::forget("sigap_evp_active_{$id}");

        $now = Carbon::now();
        $reason = 'Pembatalan Aman EVP: Permintaan prioritas darurat dibatalkan. Menyelesaikan transisi kuning dan all-red sebelum kembali ke siklus normal.';

        SystemStatusLog::create([
            'intersection_id' => $id,
            'previous_mode' => 'EVP_ACTIVE',
            'new_mode' => 'ATCS_NORMAL',
            'reason' => $reason,
            'payload' => [
                'cancelled_evp' => $active,
                'cancelled_at' => $now->toIso8601String(),
                'safe_clearance' => 'Kuning 3s -> All-Red 2s -> Siklus Normal',
            ],
            'created_at' => $now,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'EVP berhasil dibatalkan secara aman. Sistem memulihkan siklus normal.',
            'data' => [
                'is_active' => false,
                'recovery_plan' => 'Selesaikan fase kuning (3s) & all-red (2s), lalu lanjutkan ke fase reguler berikutnya.',
                'cancelled_at' => $now->toIso8601String(),
            ],
        ]);
    }

    /**
     * Menyelesaikan status EVP setelah kendaraan darurat melintasi persimpangan (Safe Recovery).
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $intersection = Intersection::find($id);

        if (!$intersection) {
            return response()->json([
                'status' => 'error',
                'message' => "Simpang dengan ID {$id} tidak ditemukan.",
            ], 404);
        }

        $active = Cache::get("sigap_evp_active_{$id}");
        Cache::forget("sigap_evp_active_{$id}");

        $now = Carbon::now();
        $vehicleLabel = $active['vehicle_label'] ?? 'Kendaraan darurat';
        $reason = "EVP Selesai: {$vehicleLabel} telah melintasi simpang. Memulihkan siklus lampu ke ATCS Normal secara bertahap.";

        SystemStatusLog::create([
            'intersection_id' => $id,
            'previous_mode' => 'EVP_ACTIVE',
            'new_mode' => 'ATCS_NORMAL',
            'reason' => $reason,
            'payload' => [
                'completed_evp' => $active,
                'completed_at' => $now->toIso8601String(),
                'recovery_phase' => 'Transisi kuning (3s) -> All-Red (2s) -> Siklus Normal',
            ],
            'created_at' => $now,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'EVP selesai. Sistem kembali ke mode reguler.',
            'data' => [
                'is_active' => false,
                'completed_at' => $now->toIso8601String(),
            ],
        ]);
    }

    /**
     * Mengambil status EVP terkini pada simpang.
     */
    public function status(int $id): JsonResponse
    {
        $intersection = Intersection::find($id);

        if (!$intersection) {
            return response()->json([
                'status' => 'error',
                'message' => "Simpang dengan ID {$id} tidak ditemukan.",
            ], 404);
        }

        $active = Cache::get("sigap_evp_active_{$id}");

        if ($active) {
            return response()->json([
                'status' => 'success',
                'data' => $active,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'is_active' => false,
                'status' => 'NORMAL',
                'message' => 'Status EVP aman. Tidak ada kendaraan darurat yang terdeteksi.',
            ],
        ]);
    }

    /**
     * Mengambil log kejadian EVP.
     */
    public function logs(int $id): JsonResponse
    {
        $logs = SystemStatusLog::where('intersection_id', $id)
            ->where(function ($query) {
                $query->where('new_mode', 'EVP_ACTIVE')
                    ->orWhere('previous_mode', 'EVP_ACTIVE');
            })
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $logs,
        ]);
    }
}
