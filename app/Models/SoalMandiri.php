<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class SoalMandiri
 *
 * @property int $id
 * @property int $modul_id
 * @property string $soal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Modul $modul
 * @property Collection|JawabanMandiri[] $jawaban_mandiris
 */
class SoalMandiri extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'soal_mandiris';

    protected $casts = [
        'modul_id' => 'int',
    ];

    protected $fillable = [
        'modul_id',
        'soal',
    ];

    public function modul()
    {
        return $this->belongsTo(Modul::class);
    }

    public function jawaban_mandiris()
    {
        return $this->hasMany(JawabanMandiri::class, 'soal_id');
    }
}
