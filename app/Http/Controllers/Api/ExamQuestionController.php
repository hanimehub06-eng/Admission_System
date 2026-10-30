<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\ExamQuestion;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Task 3.1: the College Dean writes multiple-choice entrance exam questions
 * for their own college. Body: { question, choices: string[2..6], answer: index }
 */
class ExamQuestionController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $questions = ExamQuestion::where('college_id', $this->college($request)->id)->orderBy('id')->get();

        return response()->json(['data' => $questions->map->toApi()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $college = $this->college($request);
        $question = ExamQuestion::create($this->validated($request) + ['college_id' => $college->id, 'created_by' => $request->user()->id]);
        $this->audit->record('exam_question.created', "Added an entrance exam question for {$college->code}", $question);

        return response()->json(['data' => $question->toApi()], 201);
    }

    public function update(Request $request, ExamQuestion $question): JsonResponse
    {
        $this->own($request, $question);
        $question->update($this->validated($request));
        $this->audit->record('exam_question.updated', 'Edited an entrance exam question', $question);

        return response()->json(['data' => $question->toApi()]);
    }

    public function destroy(Request $request, ExamQuestion $question): Response
    {
        $this->own($request, $question);
        $this->audit->record('exam_question.deleted', 'Deleted an entrance exam question', $question);
        $question->delete();

        return response()->noContent();
    }

    private function college(Request $request): College
    {
        return $request->user()->deanOf ?? abort(403, 'No college is assigned to your account.');
    }

    // Another college's question answers 404, so its existence isn't revealed
    private function own(Request $request, ExamQuestion $question): void
    {
        abort_unless($question->college_id === $this->college($request)->id, 404);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'choices' => ['required', 'array', 'min:2', 'max:6'],
            'choices.*' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'integer', 'min:0'],
        ]);
        if ($data['answer'] >= count($data['choices'])) {
            throw ValidationException::withMessages(['answer' => 'The answer must be one of the choices.']);
        }
        $data['choices'] = array_values($data['choices']);

        return $data;
    }
}
