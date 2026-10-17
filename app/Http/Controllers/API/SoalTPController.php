<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SoalTp;
use Illuminate\Http\Request;

class SoalTPController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $id)
    {
        try {
            // Validasi input
            $request->validate([
                'soal' => 'required|string|max:10000',
                'enable_file_upload' => 'sometimes|boolean',
            ]);

            // Menyimpan soal baru
            $soal = SoalTp::create([
                'modul_id' => $id,
                'soal' => $request->soal,
                'enable_file_upload' => $request->boolean('enable_file_upload'),
            ]);

            return response()->json([
                'message' => 'Soal berhasil ditambahkan',
                'data' => $soal,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat menambahkan soal.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            // Mengambil soal berdasarkan modul_id
            $all_tp = SoalTp::where('modul_id', $id)->get();
            if ($all_tp->isEmpty()) {
                return response()->json([
                    'message' => "Tidak ada soal ditemukan untuk modul ID $id.",
                ], 404);
            }

            return response()->json([
                'message' => 'Soal TP retrieved successfully.',
                'data' => $all_tp,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengambil soal.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Validasi input
            $request->validate([
                'modul_id' => 'required|integer|exists:moduls,id',
                // "isEssay" => "required|boolean",
                // "isProgram" => "required|boolean",
                'soal' => 'required|string|max:10000',
                'enable_file_upload' => 'sometimes|boolean',
            ]);
            $soal = SoalTp::find($id);
            if (! $soal) {
                return response()->json([
                    'message' => "Soal dengan ID $id tidak ditemukan.",
                ], 404);
            }
            if ($request->soal != $request->oldSoal) {
                $existingSoal = SoalTp::where('soal', $request->soal)->first();
                if ($existingSoal) {
                    return response()->json([
                        'message' => 'Soal dengan pertanyaan tersebut sudah terdaftar.',
                    ], 400);
                }
            }
            // Update soal
            $soal->modul_id = $request->modul_id;
            // $soal->isEssay = $request->isEssay;
            // $soal->isProgram = $request->isProgram;
            $soal->soal = $request->soal;
            if ($request->has('enable_file_upload')) {
                $soal->enable_file_upload =
                    $request->boolean('enable_file_upload');
            }
            $soal->updated_at = now();
            $soal->save();

            return response()->json([
                'message' => 'Soal berhasil diupdate',
                'data' => $soal,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat memperbarui soal.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            // Cek apakah soal ada
            $soal = SoalTp::find($id);
            if (! $soal) {
                return response()->json([
                    'message' => "Soal dengan ID $id tidak ditemukan.",
                ], 404);
            }

            // Tolak penghapusan selama soal masih memiliki jawaban praktikan
            $jumlahJawaban = $soal->jawaban()->count();
            if ($jumlahJawaban > 0) {
                return response()->json([
                    'code' => 'SOAL_HAS_ANSWERS',
                    'count' => $jumlahJawaban,
                    'message' => "Soal dengan ID $id tidak dapat dihapus karena masih memiliki $jumlahJawaban jawaban.",
                ], 409);
            }

            $soal->delete();

            return response()->json([
                'message' => 'Soal berhasil dihapus',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat menghapus soal.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reset()
    {
        try {
            SoalTp::truncate();

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat mereset soal.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
