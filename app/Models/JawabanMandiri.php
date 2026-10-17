<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class JawabanMandiri
 *
 * @property int $id
 * @property int $praktikan_id
 * @property int $soal_id
 * @property int $modul_id
 * @property int $submission_id
 * @property string $jawaban
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property JawabanSubmission $submission
 * @property Modul $modul
 * @property Praktikan $praktikan
 * @property SoalMandiri $soal_mandiri
 */
class JawabanMandiri extends Model
{
    protected $table = 'jawaban_mandiris';

    protected $casts = [
        'praktikan_id' => 'int',
        'soal_id' => 'int',
        'modul_id' => 'int',
        'submission_id' => 'int',
    ];

    protected $fillable = [
        'praktikan_id',
        'soal_id',
        'modul_id',
        'submission_id',
        'jawaban',
    ];

    public function submission()
    {
        return $this->belongsTo(JawabanSubmission::class, 'submission_id');
    }

    public function modul()
    {
        return $this->belongsTo(Modul::class);
    }

    public function praktikan()
    {
        return $this->belongsTo(Praktikan::class);
    }

    public function soal_mandiri()
    {
        return $this->belongsTo(SoalMandiri::class, 'soal_id');
    }
}
