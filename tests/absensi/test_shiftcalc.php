<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/ShiftCalc.php';

// TELAT
eqv(ShiftCalc::hitungTelat('08:00:00','08:00:00',15), 0, 'tepat jam mulai → 0');
eqv(ShiftCalc::hitungTelat('08:10:00','08:00:00',15), 0, 'dalam toleransi (+10, tol 15) → 0');
eqv(ShiftCalc::hitungTelat('08:20:00','08:00:00',15), 5, 'lewat toleransi (+20, tol 15) → 5');
eqv(ShiftCalc::hitungTelat('07:50:00','08:00:00',15), 0, 'datang lebih awal → 0');
eqv(ShiftCalc::hitungTelat('08:46:00','08:00:00',15), 31, '+46 tol15 → 31');

// LEMBUR
eqv(ShiftCalc::hitungLembur('16:00:00','16:00:00',30), 0, 'pulang tepat → 0');
eqv(ShiftCalc::hitungLembur('16:20:00','16:00:00',30), 0, 'dalam ambang (+20, after 30) → 0');
eqv(ShiftCalc::hitungLembur('16:45:00','16:00:00',30), 45, 'lewat ambang (+45) → 45');
eqv(ShiftCalc::hitungLembur('15:30:00','16:00:00',30), 0, 'pulang awal → 0');

echo "ALL OK\n";
