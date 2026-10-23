<?php

namespace App\Http\Controllers\Api\Office;

use App\Http\Controllers\Controller;
use App\Http\Presenters\OfficeStatePresenter;
use Illuminate\Http\Request;
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
}
