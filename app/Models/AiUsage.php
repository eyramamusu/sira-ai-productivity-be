<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    protected $table = 'ai_usage';

    protected $fillable = ['user_id', 'date', 'requests', 'input_tokens', 'output_tokens'];

    protected function casts(): array
    {
        return ['requests' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }
}
