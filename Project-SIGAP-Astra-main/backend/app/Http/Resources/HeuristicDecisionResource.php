<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HeuristicDecisionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'intersection_id' => $this->intersection_id,
            'signal_phase_id' => $this->signal_phase_id,
            'signal_phase' => $this->whenLoaded('signalPhase', function () {
                return [
                    'id' => $this->signalPhase->id,
                    'phase_code' => $this->signalPhase->phase_code,
                    'name' => $this->signalPhase->name,
                ];
            }),
            'proposed_duration_seconds' => $this->proposed_duration_seconds,
            'current_cycle_seconds' => $this->current_cycle_seconds,
            'reason' => $this->reason,
            'status' => $this->status,
            'priority_score' => $this->decision_payload['priority_score'] ?? null,
            'has_emergency' => $this->decision_payload['has_emergency'] ?? false,
            'rule_applied' => $this->decision_payload['rule_applied'] ?? null,
            'payload' => $this->decision_payload,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
