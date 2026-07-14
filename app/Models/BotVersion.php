<?php

// app/Models/BotVersion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BotVersion extends Model
{
    use SoftDeletes;
    use HasUuids;

    protected $fillable = [
        'bot_id', 'version_number', 'status',
        'changelog', 'created_by', 'published_at',
    ];

    protected $casts = ['published_at' => 'datetime'];

    public function bot()
    {
        return $this->belongsTo(Bot::class);
    }

    public function dialogs()
    {
        return $this->hasMany(Dialog::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
