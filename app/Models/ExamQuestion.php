<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['college_id', 'question', 'choices', 'answer', 'created_by'])]
class ExamQuestion extends Model
{
    protected function casts(): array
    {
        return ['choices' => 'array', 'answer' => 'integer'];
    }

    public function toApi(): array
    {
        return ['id' => $this->id, 'question' => $this->question, 'choices' => $this->choices, 'answer' => $this->answer];
    }
}
