# Edit Layanan pada Order LUNAS — Design Spec

> LaMaSy. Tanggal: 2026-07-02.

## Goal

Saat order berstatus bayar **lunas** di-edit layanannya (tambah/kurang item atau ubah diskon) sehingga total berubah, sistem memandu kasir lewat **gerbang uang eksplisit**: total naik → status turun ke DP + tagihan selisih via WA; total turun → kasir pilih **refund tunai** (tercatat kas keluar) atau **masuk deposit** pelanggan. `sisa_bayar` tidak pernah negatif, kas konsisten, semua tercatat di log order.

## Latar (perilaku hari ini — bermasalah)

Endpoint `orders.php action=update` me-recompute `total/sisa_bayar/status_bayar` dari items + `dp` lama:
- Total naik → status diam-diam turun ke `dp` (tanpa peringatan/tagihan).
- Total turun → `sisa_bayar = total − dp` tersimpan **NEGATIF**; kelebihan bayar pelanggan menguap tanpa refund/deposit.
- Kas masuk lama tidak disesuaikan → omzet vs kas tak sinkron.
- Edit item bisa lewat permission `orders.update_status` (harusnya `orders.edit`).

Skenario nyata: (1) saat penimbangan/produksi ditemukan item tambahan (mis. sprei nyelip di cucian reguler) — pelanggan sudah pulang; (2) koreksi salah input kasir setelah pembayaran.

## Keputusan Desain (terkunci)

- **Pendekatan A — adjust-in-place**: order tetap satu; edit diizinkan; gerbang konfirmasi eksplisit saat order lunas & total berubah. (Ditolak: lock+unlock = +1 langkah; order adendum = 2 nota utk 1 kantong.)
- Kelebihan bayar: **kasir memilih** refund tunai ATAU masuk deposit (bukan otomatis).
- Kurang bayar: status turun ke `dp` + **WA tagih selisih** + banner di detail; pelunasan lewat tombol **Update Bayar** existing.
- Poin loyalty yang sudah earned tidak di-adjust (out of scope v1, tercatat di sini).

## Alur (server, `orders.php action=update`)

1. Hitung `$totalBaru` dari items+diskon (logika existing). Ambil `$totalLama`, `$dpLama`, `$sbayarLama` dari DB.
2. **Gerbang**: jika `$sbayarLama === 'lunas'` DAN `$itemsChanged || diskon berubah` DAN `$totalBaru != $totalLama` DAN request TIDAK membawa `confirm_resolution` yang valid → rollback ringan (belum tulis apa pun) dan balas:
   - naik: `{"need_confirm":"kurang_bayar","selisih":<totalBaru-dpLama>,"total_baru":<int>}`
   - turun: `{"need_confirm":"kelebihan","selisih":<dpLama-totalBaru>,"total_baru":<int>,"bisa_deposit":<bool pelanggan_id!=null>}`
3. Jika membawa `confirm_resolution`:
   - **`tagih`** (naik): simpan dgn `dp = dpLama`, `sisa_bayar = totalBaru − dpLama`, `status_bayar = 'dp'`. Log tipe `bayar`: `"Lunas → DP: penambahan layanan, kurang Rp {selisih}"`. Response menyertakan `wa_url` (wa.me) bila ada telepon.
   - **`refund_tunai`** (turun): simpan dgn `dp = totalBaru`, `sisa_bayar = 0`, `status_bayar = 'lunas'`; INSERT `hl_kas` (tipe `keluar`, kategori `Refund Order`, keterangan `"Refund koreksi order {no_order} — {nama}"`, jumlah = selisih, `ref_order`, `created_by`). Log tipe `bayar` mencatat refund.
   - **`ke_deposit`** (turun): syarat `pelanggan_id` ada; simpan header sama spt refund_tunai; panggil `DepositManager::topup(...)` jumlah = selisih, **bonus 0**, metode `'refund_order'`, catatan `"Refund koreksi order {no_order}"`. Log tipe `bayar` mencatat konversi ke deposit. (Kas TIDAK dicatat keluar — uang tetap di laci, berubah jadi liabilitas deposit.)
4. Order non-lunas, atau lunas tapi total tak berubah (edit catatan/status saja): perilaku lama tanpa gerbang.
5. **Clamp global**: dalam semua jalur, `sisa_bayar = max(0, ...)` — tak pernah negatif tersimpan.

## Alur (klien, modal edit order di `orders.php`)

1. `saveOrderEdit()` kirim spt biasa. Jika response `need_confirm`:
   - `kurang_bayar` → `lmConfirm("Order ini LUNAS. Perubahan membuat kurang bayar Rp X. Lanjutkan?")` → ya → kirim ulang payload + `confirm_resolution:'tagih'`.
   - `kelebihan` → dialog pilihan (modal kecil 2 tombol): "💵 Refund Tunai Rp X" / "💳 Masukkan ke Deposit" (tombol deposit disembunyikan bila `bisa_deposit=false`) + batal → kirim ulang + resolusi terpilih.
2. Sukses `tagih` → tampilkan toast + tombol/auto-buka `wa_url` (template: `"Halo {nama}, saat penimbangan cucian order {no_order} terdapat layanan tambahan sehingga total menjadi Rp {total}. Kekurangan Rp {selisih} dapat dibayar saat pengambilan. Terima kasih 🙏 — {outlet}"`).
3. Detail order: bila `status_bayar='dp'` dan log terakhir tipe bayar mengandung "Lunas → DP" → banner kuning "⚠️ Kurang bayar Rp {sisa} (order sebelumnya lunas)". (Sederhana: render banner cukup dari `status_bayar='dp' && sisa_bayar>0` yang sudah ada + label tambahan dari log terakhir bila tersedia.)

## Pagar Permission

Di endpoint update: bila `$itemsChanged || diskon berubah` → wajib `hasPermission('orders.edit')`; kalau tidak → `{"error":"Butuh izin edit order utk mengubah layanan"}`. Perubahan status proses murni tetap boleh dgn `orders.update_status` (perilaku existing).

## Komponen & File

| File | Aksi | Tanggung jawab |
|---|---|---|
| `core/OrderEditResolver.php` | CREATE | Helper murni `resolve(array $ctx): array` — input totalLama/dpLama/sbayarLama/totalBaru/resolusi → output `[need_confirm?|dp,sisa,status,aksi_uang]`. Tanpa sesi/DB → unit-testable CLI |
| `orders.php` | MODIFY | `action=update` memakai resolver (deteksi, need_confirm, eksekusi 3 resolusi + kas/deposit, clamp, guard permission, log, wa_url) + JS modal konfirmasi/pilihan + banner detail |
| `core/DepositManager.php` | PAKAI (tanpa ubah) | `topup()` utk resolusi ke_deposit |
| `tests/orders/test_edit_lunas.php` | CREATE | Unit test resolver (murni, tanpa DB) + test integrasi kas/deposit via seed DB langsung (pola tests/coin) |

Tidak ada perubahan skema DB.

## Error Handling / Edge Cases

| Kondisi | Perilaku |
|---|---|
| `confirm_resolution` tak valid utk arah selisih (mis. `tagih` saat total turun) | Tolak `{"error":"Resolusi tidak sesuai"}` |
| `ke_deposit` tanpa `pelanggan_id` | Tolak + klien memang tak menampilkan opsi (bisa_deposit=false) |
| `DepositManager::topup` gagal | Rollback seluruh transaksi edit (semua dalam satu transaction existing) |
| Order lunas via saldo deposit sebelumnya | v1: tetap pilihan kasir (refund tunai / kembali ke deposit) — tidak auto-ke-sumber |
| Selisih = 0 (komposisi berubah, total sama) | Tanpa gerbang, simpan biasa |
| Race dobel-submit resolusi | Transaksi + cek ulang `status_bayar` lama di dalam transaction (`FOR UPDATE` pada baris order) |
| Poin loyalty sudah earned lalu total berubah | TIDAK di-adjust (out of scope v1) |

## Keamanan

- CSRF existing (`verifyCsrf`) tetap.
- Guard tenant+outlet existing tetap; baris order dikunci `FOR UPDATE` dalam transaction saat resolusi uang.
- Refund tunai tercatat di `hl_kas` dengan `created_by` — auditable; log order menyimpan siapa & kapan.
- Template WA di-escape/urlencode; tidak menuliskan data sensitif selain ringkasan order.

## Testing

- **Unit (`tests/orders/test_edit_lunas.php`)**: seed tenant/outlet/pelanggan/order lunas temp (pola clone tests/coin, cleanup `register_shutdown_function`): (1) naik tanpa resolusi → need_confirm kurang_bayar; (2) naik+`tagih` → dp/sisa/status benar + log; (3) turun tanpa resolusi → need_confirm kelebihan; (4) turun+`refund_tunai` → kas keluar tercatat, sisa 0, lunas; (5) turun+`ke_deposit` → saldo_deposit naik, hl_deposit_topup tercatat bonus 0, kas tak berkurang; (6) guard: user tanpa orders.edit ditolak saat itemsChanged; (7) sisa tak pernah negatif. Catatan: endpoint butuh sesi — test memanggil fungsi via HTTP tidak bisa; struktur test = ekstrak logika resolusi ke fungsi helper murni di orders.php yang bisa di-require CLI, ATAU test lewat HTTP dgn sesi demo. Keputusan: **ekstrak helper murni** `ordersResolveLunasEdit(array $ctx): array` (input: totalLama/dpLama/totalBaru/resolusi → output: dp/sisa/status/aksi uang) di `core/OrderEditResolver.php` sehingga unit-testable tanpa sesi; endpoint memakainya.
- **Manual E2E via `/demo`**: edit order lunas DMO tambah item → dialog → WA; kurangi → pilihan refund/deposit → cek kas & deposit.

## Out of Scope

- Penyesuaian poin loyalty yang sudah earned.
- Refund otomatis ke metode pembayaran asal (Midtrans dsb).
- Perubahan skema DB / halaman baru.
- Notifikasi push/WA otomatis via gateway (WA = wa.me manual).

## References

- `orders.php:172` (action=update — titik gerbang), `orders.php:420,590` (Update Bayar existing), `core/DepositManager.php` (`topup`), `core/Loyalty.php:151` (earn idempoten, konteks out-of-scope), [[project-coin-monetization]] (WA = wa.me).
