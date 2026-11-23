<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function store(Request $request, Assignment $assignment): RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        if (!Auth::user()->isMahasiswa()) {
            abort(403, 'Akses ditolak. Pengumpulan tugas hanya dapat dilakukan oleh Mahasiswa.');
        }

        $user = Auth::user();

        $enrollment = $assignment->course->students()->where('users.id', $user->id)->first();
        if (!$enrollment || $enrollment->pivot->status !== 'approved') {
            abort(403, 'Akses ditolak. Anda belum disetujui oleh Dosen untuk mengikuti mata kuliah tugas ini.');
        }

        if ($assignment->isOverdue()) {
            return back()->with('error', 'Gagal mengumpulkan: Batas waktu pengumpulan (deadline) tugas telah berakhir.');
        }

        $validated = $request->validate([
            'jawaban' => ['required', 'string', 'min:10', 'max:20000'],
        ], [
            'jawaban.required' => 'Teks jawaban tugas wajib diisi.',
            'jawaban.min' => 'Jawaban terlalu pendek. Tuliskan jawaban minimal 10 karakter.',
            'jawaban.max' => 'Jawaban melebihi batas maksimal 20.000 karakter.',
        ]);

        Submission::updateOrCreate(
            [
                'assignment_id' => $assignment->id,
                'user_id' => $user->id,
            ],
            [
                'jawaban' => $validated['jawaban'],
                'submitted_at' => now(),
            ]
        );

        return back()->with('success', 'Jawaban tugas Anda berhasil disimpan ke sistem.');
    }

    public function grade(Request $request, Submission $submission): RedirectResponse
    {
        // TODO 5a (grade): Cek apakah dosen adalah pengampu mata kuliah, validasi nilai (0-100) & catatan_dosen, lalu update submission.
        // 1. Pastikan $submission->assignment->course->dosen_id === Auth::id(), jika tidak abort(403).
        // 2. Validasi input 'nilai' (required|integer|min:0|max:100) dan 'catatan_dosen' (nullable|string|max:1000).
        // 3. Simpan perubahan menggunakan $submission->update(...) dan redirect back() dengan pesan sukses.

        $submission->loadMissing('assignment.course');

        if ((int) optional($submission->assignment->course)->dosen_id !== (int) Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah tugas ini.');
        }

        $validated = $request->validate([
            'nilai' => ['required', 'integer', 'min:0', 'max:100'],
            'catatan_dosen' => ['nullable', 'string', 'max:1000'],
        ], [
            'nilai.required' => 'Nilai wajib diisi.',
            'nilai.integer' => 'Nilai harus berupa bilangan bulat.',
            'nilai.min' => 'Nilai minimal adalah 0.',
            'nilai.max' => 'Nilai maksimal adalah 100.',
            'catatan_dosen.string' => 'Catatan dosen harus berupa teks.',
            'catatan_dosen.max' => 'Catatan dosen maksimal 1000 karakter.',
        ]);

        $submission->update([
            'nilai' => $validated['nilai'],
            'catatan_dosen' => $validated['catatan_dosen'] ?? null,
        ]);

        return back()->with('success', 'Nilai dan catatan dosen berhasil disimpan.');
    }

    public function exportCsv(Assignment $assignment): StreamedResponse
    {
        // TODO 5b (exportCsv): Cek kepemilikan dosen, lalu gunakan response()->streamDownload() dan fputcsv() untuk mengunduh data nilai mahasiswa.
        // 1. Pastikan $assignment->course->dosen_id === Auth::id(), jika tidak abort(403).
        // 2. Muat relasi course.approvedStudents dan submissions dari $assignment.
        // 3. Kembalikan response()->streamDownload(...) yang menulis header & baris CSV menggunakan fopen('php://output', 'w') dan fputcsv().

        $assignment->loadMissing('course');

        if ((int) optional($assignment->course)->dosen_id !== (int) Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah tugas ini.');
        }

        $assignment->load([
            'course.approvedStudents',
            'submissions.user',
        ]);

        $fileName = 'rekap-nilai-' . str($assignment->judul ?? 'tugas')->slug() . '.csv';

        return response()->streamDownload(function () use ($assignment) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM agar karakter dan tanda baca rapi saat dibuka di Excel / Calc
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'No',
                'Nama Mahasiswa',
                'NIM / Nomor Induk',
                'Email',
                'Status Pengumpulan',
                'Waktu Pengumpulan',
                'Nilai',
                'Status Kelulusan',
                'Catatan Dosen',
            ]);

            $submissionsByUser = $assignment->submissions->keyBy('user_id');
            $students = $assignment->course->approvedStudents ?? collect();

            $rows = $students->isNotEmpty()
                ? $students->sortBy('name')->values()
                : $assignment->submissions->map->user->filter()->sortBy('name')->values();

            foreach ($rows as $index => $student) {
                $submission = $submissionsByUser->get($student->id);
                $hasSubmitted = $submission && !is_null($submission->submitted_at);
                $nilai = $submission && !is_null($submission->nilai) ? (int) $submission->nilai : null;

                $statusKelulusan = 'Belum Dinilai';
                if (!is_null($nilai)) {
                    $statusKelulusan = $submission->status_kelulusan ?? ($nilai >= 60 ? 'Lulus' : 'Tidak Lulus');
                }

                fputcsv($handle, [
                    $index + 1,
                    $student->name ?? '-',
                    $student->nomor_induk ?? $student->nim ?? '-',
                    $student->email ?? '-',
                    $hasSubmitted ? 'Sudah Mengumpulkan' : 'Belum Mengumpulkan',
                    $hasSubmitted ? optional($submission->submitted_at)->format('Y-m-d H:i:s') : '-',
                    !is_null($nilai) ? $nilai : '-',
                    $statusKelulusan,
                    $submission->catatan_dosen ?? '-',
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}