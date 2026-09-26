<?php

namespace App\Services;

use App\Models\HeuristicDecision;
use App\Models\Intersection;
use App\Models\SignalPhase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HeuristicDecisionService
{
    // Bobot Satuan Mobil Penumpang (SMP / PCU)
    public const WEIGHT_MOTORCYCLE = 0.5;
    public const WEIGHT_CAR = 1.0;
    public const WEIGHT_BUS = 2.0;
    public const WEIGHT_TRUCK = 2.0;
    public const WEIGHT_EMERGENCY = 999.0;

    /**
     * Menjalankan evaluasi heuristik untuk menentukan prioritas fase dan durasi hijau.
     *
     * @param Intersection $intersection
     * @param array|string|null $trafficInput (array data pengukuran atau nama preset scenario)
     * @return HeuristicDecision
     */
    public function evaluate(Intersection $intersection, array|string|null $trafficInput = null): HeuristicDecision
    {
        return DB::transaction(function () use ($intersection, $trafficInput) {
            $phases = $intersection->signalPhases()->get()->keyBy('phase_code');

            $phaseEW = $phases->get('PHASE_EW');
            $phaseNS = $phases->get('PHASE_NS');

            if (!$phaseEW || !$phaseNS) {
                throw new \RuntimeException('Fase sinyal PHASE_EW dan PHASE_NS belum terkonfigurasi pada simpang ini.');
            }

            // Normalisasi data input pengukuran 4 arah
            $metrics = $this->resolveTrafficData($trafficInput);

            // Hitung skor untuk masing-masing fase
            $ewEval = $this->calculatePhaseScore($metrics['WEST'], $metrics['EAST'], 'Barat', 'Timur');
            $nsEval = $this->calculatePhaseScore($metrics['NORTH'], $metrics['SOUTH'], 'Utara', 'Selatan');

            // Evaluasi aturan prioritas (Heuristic Rules)
            $decision = $this->determineWinningPhase($phaseEW, $phaseNS, $ewEval, $nsEval);

            // Simpan keputusan ke database
            return HeuristicDecision::create([
                'intersection_id' => $intersection->id,
                'signal_phase_id' => $decision['winning_phase']->id,
                'proposed_duration_seconds' => $decision['proposed_duration'],
                'current_cycle_seconds' => $decision['current_cycle_seconds'],
                'reason' => $decision['reason'],
                'decision_payload' => [
                    'winning_phase_code' => $decision['winning_phase']->phase_code,
                    'winning_phase_name' => $decision['winning_phase']->name,
                    'priority_score' => $decision['priority_score'],
                    'has_emergency' => $decision['has_emergency'],
                    'rule_applied' => $decision['rule_applied'],
                    'phase_ew' => $ewEval,
                    'phase_ns' => $nsEval,
                    'timestamp' => Carbon::now()->toIso8601String(),
                ],
                'status' => 'PROPOSED',
                'decided_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Hitung total SMP, kepadatan, dan skor urgensi dari dua pendekatan berlawanan dalam satu fase.
     */
    private function calculatePhaseScore(array $appA, array $appB, string $nameA, string $nameB): array
    {
        $smpA = $this->calculateSmp($appA['counts']);
        $smpB = $this->calculateSmp($appB['counts']);
        $hasEmergencyA = ($appA['counts']['ambulance'] ?? 0) > 0 || ($appA['counts']['firetruck'] ?? 0) > 0;
        $hasEmergencyB = ($appB['counts']['ambulance'] ?? 0) > 0 || ($appB['counts']['firetruck'] ?? 0) > 0;

        $dominantA = $smpA >= $smpB;
        $maxSmp = max($smpA, $smpB);
        $totalSmp = $smpA + $smpB;
        $dominantName = $dominantA ? $nameA : $nameB;
        $waitingTime = max($appA['waiting_seconds'] ?? 30, $appB['waiting_seconds'] ?? 30);

        // Formula skor prioritas fase: Volume SMP (70%) + Waktu Tunggu Anti-Starvation (30%)
        $score = ($maxSmp * 1.5) + ($waitingTime * 0.4);

        if ($hasEmergencyA || $hasEmergencyB) {
            $score += self::WEIGHT_EMERGENCY;
        }

        return [
            'name_a' => $nameA,
            'name_b' => $nameB,
            'dominant_approach' => $dominantName,
            'smp_a' => round($smpA, 1),
            'smp_b' => round($smpB, 1),
            'max_smp' => round($maxSmp, 1),
            'total_smp' => round($totalSmp, 1),
            'waiting_time_seconds' => $waitingTime,
            'score' => round($score, 1),
            'has_emergency' => $hasEmergencyA || $hasEmergencyB,
            'emergency_kind' => ($appA['counts']['ambulance'] ?? 0) > 0 || ($appB['counts']['ambulance'] ?? 0) > 0 ? 'Ambulans' : (($appA['counts']['firetruck'] ?? 0) > 0 || ($appB['counts']['firetruck'] ?? 0) > 0 ? 'Pemadam' : null),
            'counts_a' => $appA['counts'],
            'counts_b' => $appB['counts'],
        ];
    }

    /**
     * Konversi jumlah unit kendaraan menjadi Satuan Mobil Penumpang (SMP).
     */
    private function calculateSmp(array $counts): float
    {
        $motor = $counts['motorcycle'] ?? 0;
        $car = $counts['car'] ?? 0;
        $bus = $counts['bus'] ?? 0;
        $truck = $counts['truck'] ?? 0;

        return ($motor * self::WEIGHT_MOTORCYCLE)
             + ($car * self::WEIGHT_CAR)
             + ($bus * self::WEIGHT_BUS)
             + ($truck * self::WEIGHT_TRUCK);
    }

    /**
     * Membandingkan Fase EW vs Fase NS berdasarkan aturan heuristik SIGAP.
     */
    private function determineWinningPhase(SignalPhase $phaseEW, SignalPhase $phaseNS, array $ew, array $ns): array
    {
        $hasEmergencyEW = $ew['has_emergency'];
        $hasEmergencyNS = $ns['has_emergency'];

        // Aturan 1: Prioritas Mutlak Kendaraan Darurat (EVP Override)
        if ($hasEmergencyEW && !$hasEmergencyNS) {
            $winningPhase = $phaseEW;
            $duration = min(45, $winningPhase->max_duration_seconds);
            $reason = "Emergency Vehicle Priority (EVP): Terdeteksi {$ew['emergency_kind']} dari Arah {$ew['dominant_approach']}. Fase Barat–Timur diprioritaskan hijau 45 detik untuk jalur evakuasi aman.";
            $priorityScore = 100.0;
            $rule = 'EVP_OVERRIDE';
        } elseif ($hasEmergencyNS && !$hasEmergencyEW) {
            $winningPhase = $phaseNS;
            $duration = min(45, $winningPhase->max_duration_seconds);
            $reason = "Emergency Vehicle Priority (EVP): Terdeteksi {$ns['emergency_kind']} dari Arah {$ns['dominant_approach']}. Fase Utara–Selatan diprioritaskan hijau 45 detik untuk jalur evakuasi aman.";
            $priorityScore = 100.0;
            $rule = 'EVP_OVERRIDE';
        }
        // Aturan 2: Perbandingan Skor Prioritas Beban Antrean (EW vs NS)
        elseif ($ew['score'] >= $ns['score']) {
            $winningPhase = $phaseEW;
            $duration = $this->calculateAdaptiveDuration($phaseEW, $ew['max_smp']);
            $diff = round($ew['score'] - $ns['score'], 1);
            $priorityScore = min(98.0, round(50 + ($diff * 0.8), 1));
            $delta = $duration - $phaseEW->default_duration_seconds;
            $deltaStr = $delta >= 0 ? "+{$delta}s" : "{$delta}s";
            $reason = "Fase Barat–Timur diprioritaskan (Skor: {$priorityScore}). Beban antrean Arah {$ew['dominant_approach']} mencapai {$ew['max_smp']} SMP (total fase {$ew['total_smp']} SMP). Durasi hijau disesuaikan menjadi {$duration} detik ({$deltaStr} dari default {$phaseEW->default_duration_seconds}s).";
            $rule = $ew['score'] == $ns['score'] ? 'TIE_BREAKER_EW' : 'VOLUME_PRIORITY';
        } else {
            $winningPhase = $phaseNS;
            $duration = $this->calculateAdaptiveDuration($phaseNS, $ns['max_smp']);
            $diff = round($ns['score'] - $ew['score'], 1);
            $priorityScore = min(98.0, round(50 + ($diff * 0.8), 1));
            $delta = $duration - $phaseNS->default_duration_seconds;
            $deltaStr = $delta >= 0 ? "+{$delta}s" : "{$delta}s";
            $reason = "Fase Utara–Selatan diprioritaskan (Skor: {$priorityScore}). Beban antrean Arah {$ns['dominant_approach']} mencapai {$ns['max_smp']} SMP (total fase {$ns['total_smp']} SMP). Durasi hijau disesuaikan menjadi {$duration} detik ({$deltaStr} dari default {$phaseNS->default_duration_seconds}s).";
            $rule = 'VOLUME_PRIORITY';
        }

        $cycleSeconds = $duration + $winningPhase->amber_duration_seconds + $winningPhase->all_red_duration_seconds;

        return [
            'winning_phase' => $winningPhase,
            'proposed_duration' => $duration,
            'current_cycle_seconds' => $cycleSeconds,
            'reason' => $reason,
            'priority_score' => $priorityScore,
            'has_emergency' => $hasEmergencyEW || $hasEmergencyNS,
            'rule_applied' => $rule,
        ];
    }

    /**
     * Hitung durasi hijau adaptif dengan interpolasi linear berbatas min dan max.
     */
    private function calculateAdaptiveDuration(SignalPhase $phase, float $smp): int
    {
        $min = $phase->min_duration_seconds;       // 15 detik
        $max = $phase->max_duration_seconds;       // 60 detik
        $default = $phase->default_duration_seconds; // 30s atau 25s

        // SMP baseline: 10 SMP = durasi default; 35+ SMP = durasi mendekati maksimum
        if ($smp <= 5.0) {
            $duration = max($min, $default - 10);
        } elseif ($smp <= 15.0) {
            $duration = $default;
        } elseif ($smp <= 35.0) {
            $ratio = ($smp - 15.0) / 20.0;
            $duration = (int) round($default + ($ratio * ($max - $default)));
        } else {
            $duration = min($max, (int) round($max - 3));
        }

        return max($min, min($max, $duration));
    }

    /**
     * Menyusun data masukan lalu lintas (dari input array atau skenario simulasi).
     */
    private function resolveTrafficData(array|string|null $input): array
    {
        if (is_array($input) && isset($input['WEST'], $input['NORTH'], $input['EAST'], $input['SOUTH'])) {
            return $input;
        }

        $scenario = is_string($input) ? strtolower(trim($input)) : 'default';

        return match ($scenario) {
            'west_peak', 'macet_barat' => [
                'WEST' => ['counts' => ['motorcycle' => 28, 'car' => 18, 'bus' => 2, 'truck' => 1, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 50],
                'EAST' => ['counts' => ['motorcycle' => 14, 'car' => 8, 'bus' => 1, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 45],
                'NORTH' => ['counts' => ['motorcycle' => 8, 'car' => 5, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 25],
                'SOUTH' => ['counts' => ['motorcycle' => 10, 'car' => 4, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 20],
            ],
            'north_peak', 'macet_utara' => [
                'WEST' => ['counts' => ['motorcycle' => 10, 'car' => 6, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 20],
                'EAST' => ['counts' => ['motorcycle' => 8, 'car' => 4, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 25],
                'NORTH' => ['counts' => ['motorcycle' => 32, 'car' => 20, 'bus' => 3, 'truck' => 1, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 55],
                'SOUTH' => ['counts' => ['motorcycle' => 16, 'car' => 10, 'bus' => 1, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 40],
            ],
            'emergency_west', 'ambulans_barat' => [
                'WEST' => ['counts' => ['motorcycle' => 15, 'car' => 10, 'bus' => 1, 'truck' => 0, 'ambulance' => 1, 'firetruck' => 0], 'waiting_seconds' => 35],
                'EAST' => ['counts' => ['motorcycle' => 12, 'car' => 8, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 30],
                'NORTH' => ['counts' => ['motorcycle' => 14, 'car' => 7, 'bus' => 1, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 25],
                'SOUTH' => ['counts' => ['motorcycle' => 11, 'car' => 6, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 20],
            ],
            'emergency_north', 'pemadam_utara' => [
                'WEST' => ['counts' => ['motorcycle' => 14, 'car' => 9, 'bus' => 1, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 30],
                'EAST' => ['counts' => ['motorcycle' => 10, 'car' => 7, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 25],
                'NORTH' => ['counts' => ['motorcycle' => 18, 'car' => 12, 'bus' => 1, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 1], 'waiting_seconds' => 35],
                'SOUTH' => ['counts' => ['motorcycle' => 12, 'car' => 8, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 20],
            ],
            // Skenario default (Simpang Jl. Ibrahim Adjie jam sibuk - Barat lebih padat menuju Mall Tenth Ave)
            default => [
                'WEST' => ['counts' => ['motorcycle' => 22, 'car' => 14, 'bus' => 1, 'truck' => 1, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 45],
                'EAST' => ['counts' => ['motorcycle' => 16, 'car' => 9, 'bus' => 1, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 40],
                'NORTH' => ['counts' => ['motorcycle' => 12, 'car' => 7, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 30],
                'SOUTH' => ['counts' => ['motorcycle' => 11, 'car' => 5, 'bus' => 0, 'truck' => 0, 'ambulance' => 0, 'firetruck' => 0], 'waiting_seconds' => 25],
            ],
        };
    }
}
