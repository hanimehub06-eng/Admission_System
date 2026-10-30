<?php

namespace App\Http\Controllers\Api\Office;

use App\Http\Controllers\Controller;
use App\Http\Presenters\OfficeStatePresenter;
use App\Models\AdmissionPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;

class StateController extends Controller
{
    /**
     * Everything the panel shows (replaces ADM.state in localStorage).
     * Streamed: applicants are encoded and sent batch by batch, so memory
     * stays flat however many applicants there are.
     */
    public function show(Request $request, OfficeStatePresenter $presenter): StreamedJsonResponse
    {
        return response()->streamJson(['data' => $presenter->state($request->user())]);
    }

    /**
     * Live search for the top bar: up to 8 applicants of the current period
     * whose application number, LRN, name or email contains every typed word.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $query = DB::table('applications as a')
            ->join('applicants as p', 'p.id', '=', 'a.applicant_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('a.admission_period_id', AdmissionPeriod::current()->id)
            ->whereNull('a.deleted_at');
        foreach (preg_split('/\s+/', trim($data['q'])) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(fn ($q) => $q->where('a.number', 'like', $like)->orWhere('a.applicant_no', 'like', $like)->orWhere('p.lrn', 'like', $like)
                ->orWhere('p.first_name', 'like', $like)->orWhere('p.last_name', 'like', $like)->orWhere('u.email', 'like', $like));
        }

        return response()->json(['data' => $query->orderBy('p.last_name')->orderBy('p.first_name')->limit(8)
            ->get(['a.number as id', 'p.first_name', 'p.last_name', 'p.lrn', 'a.status'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => trim("{$r->last_name}, {$r->first_name}", ', '), 'lrn' => $r->lrn, 'status' => $r->status])]);
    }
}
