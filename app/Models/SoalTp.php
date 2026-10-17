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
 * Class SoalTp
 *
 * @property int $id
 * @property int $modul_id
 * @property string $soal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Modul $modul
 * @property Collection|JawabanTp[] $jawaban_tps
 * @property Collection|TempJawabantp[] $temp_jawabantps
 */
class SoalTp extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'soal_tps';

    protected $casts = [
        'modul_id' => 'int',
        'enable_file_upload' => 'boolean',
    ];

    protected $fillable = [
        'modul_id',
        'soal',
        'enable_file_upload',
    ];

    public function modul()
    {
        return $this->belongsTo(Modul::class, 'modul_id');
    }

    public function jawaban()
    {
        return $this->hasMany(JawabanTp::class, 'soal_id');
    }

    public function temp_jawabantps()
    {
        return $this->hasMany(TempJawabantp::class, 'soal_id');
    }
}
