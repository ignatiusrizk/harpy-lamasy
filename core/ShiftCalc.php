<?php
// core/ShiftCalc.php — hitung telat & lembur vs jam shift (menit). Asumsi shift dalam 1 hari.
class ShiftCalc
{
    /** Menit telat: clock-in lewat (jam mulai + toleransi). 0 kalau tepat/dalam toleransi/lebih awal. */
    public static function hitungTelat(string $jamMasuk, string $jamMulai, int $toleransiMenit): int
    {
        $selisih = strtotime($jamMasuk) - strtotime($jamMulai) - max(0, $toleransiMenit) * 60;
        return $selisih <= 0 ? 0 : (int)ceil($selisih / 60);
    }

    /** Menit lembur: clock-out lewat jam selesai, hanya kalau overshoot >= ambang. */
    public static function hitungLembur(string $jamKeluar, string $jamSelesai, int $lemburAfterMenit): int
    {
        $overshoot = strtotime($jamKeluar) - strtotime($jamSelesai);
        return ($overshoot >= max(0, $lemburAfterMenit) * 60) ? (int)floor($overshoot / 60) : 0;
    }
}
