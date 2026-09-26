<?php

namespace App\Http\Controllers;

use App\Http\Resources\HeuristicDecisionResource;
use App\Models\HeuristicDecision;
use App\Models\Intersection;
use App\Services\HeuristicDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeuristicController extends Controller
{
    /**
     * Mengambil rekomendasi keputusan heuristik terbaru untuk simpang tertentu.
     */
    public function latest(int $id, HeuristicDecisionService $service): JsonResponse
    {
        $intersection = Intersection::find($id);

        if (!$intersection) {
            return response()->json([
                'status' => 'error',
                'message' => "Simpang dengan ID {$id} tidak ditemukan.",
            ], 404);
        }

        $latest = HeuristicDecision::where('intersection_id', $id)
            ->with('signalPhase')
            ->latest('id')
            ->first();

        // Jika belum ada riwayat keputusan di database, generate evaluasi awal secara otomatis
        if (!$latest) {
            $latest = $service->evaluate($intersection, 'default');
            $latest->load('signalPhase');
        }

        return response()->json([
            'status' => 'success',
            'data' => new HeuristicDecisionResource($latest),
        ]);
    }

    /**
     * Memicu kalkulasi evaluasi keputusan heuristik baru (bisa dengan input antrean custom atau preset skenario).
     */
    public function evaluate(Request $request, int $id, HeuristicDecisionService $service): JsonResponse
    {
        $intersection = Intersection::find($id);

        if (!$intersection) {
            return response()->json([
                'status' => 'error',
                'message' => "Simpang dengan ID {$id} tidak ditemukan.",
            ], 404);
        }

        $input = $request->input('measurements') ?? $request->input('scenario') ?? 'default';

        $decision = $service->evaluate($intersection, $input);
        $decision->load('signalPhase');

        return response()->json([
            'status' => 'success',
            'message' => 'Evaluasi heuristik berhasil dikalkulasi.',
            'data' => new HeuristicDecisionResource($decision),
        ], 201);
    }

    /**
     * Mengambil daftar riwayat keputusan heuristik terakhir.
     */
    public function history(int $id): JsonResponse
    {
        $intersection = Intersection::find($id);

        if (!$intersection) {
            return response()->json([
                'status' => 'error',
                'message' => "Simpang dengan ID {$id} tidak ditemukan.",
            ], 404);
        }

        $history = HeuristicDecision::where('intersection_id', $id)
            ->with('signalPhase')
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => HeuristicDecisionResource::collection($history),
        ]);
    }
}
