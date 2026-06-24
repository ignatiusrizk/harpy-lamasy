// LAMASY — Internal Team Document Generator
const fs = require('fs');
const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  Header, Footer, AlignmentType, LevelFormat, TabStopType, TabStopPosition,
  HeadingLevel, BorderStyle, WidthType, ShadingType, PageNumber, PageBreak,
  TableOfContents, ExternalHyperlink, Bookmark, InternalHyperlink, PageOrientation
} = require('docx');

// ─── Brand ──────────────────────────────────────────────────────
const TEAL = "35E8D5";
const TEAL_DEEP = "0BC3B0";
const NAVY = "0F1C3A";
const VIOLET = "A78BFA";
const ASH = "6B7280";
const CREASE = "E5E7EB";
const SAGE = "84CC16";
const CORAL = "F43F5E";
const AMBER = "F59E0B";
const INK = "1A1F2E";

// ─── Helpers ────────────────────────────────────────────────────
const p = (text, opts = {}) => new Paragraph({
  children: [new TextRun({ text, ...opts.run })],
  ...opts.para,
});

const h1 = (text, bookmarkId) => new Paragraph({
  heading: HeadingLevel.HEADING_1,
  spacing: { before: 480, after: 200 },
  children: bookmarkId
    ? [new Bookmark({ id: bookmarkId, children: [new TextRun(text)] })]
    : [new TextRun(text)],
});

const h2 = (text) => new Paragraph({
  heading: HeadingLevel.HEADING_2,
  spacing: { before: 320, after: 120 },
  children: [new TextRun(text)],
});

const h3 = (text) => new Paragraph({
  heading: HeadingLevel.HEADING_3,
  spacing: { before: 220, after: 80 },
  children: [new TextRun(text)],
});

const bullet = (text, sub = []) => {
  const out = [new Paragraph({
    numbering: { reference: "bullets", level: 0 },
    children: typeof text === 'string'
      ? [new TextRun(text)]
      : text,
  })];
  sub.forEach(s => out.push(new Paragraph({
    numbering: { reference: "bullets", level: 1 },
    children: typeof s === 'string' ? [new TextRun(s)] : s,
  })));
  return out;
};

const numbered = (text) => new Paragraph({
  numbering: { reference: "numbers", level: 0 },
  children: typeof text === 'string' ? [new TextRun(text)] : text,
});

const code = (text) => new Paragraph({
  shading: { fill: "F3F4F6", type: ShadingType.CLEAR },
  spacing: { before: 60, after: 60 },
  children: [new TextRun({ text, font: "Menlo", size: 18 })],
});

const callout = (text, color = TEAL) => new Paragraph({
  shading: { fill: color === TEAL ? "E6FCF9" : color === VIOLET ? "F4F0FE" : "FEF3C7", type: ShadingType.CLEAR },
  border: { left: { style: BorderStyle.SINGLE, size: 24, color, space: 8 } },
  spacing: { before: 120, after: 120 },
  children: [new TextRun({ text, italics: true, color: INK })],
});

const tableBorder = { style: BorderStyle.SINGLE, size: 4, color: CREASE };
const cellBorders = { top: tableBorder, bottom: tableBorder, left: tableBorder, right: tableBorder };

const tCell = (text, opts = {}) => new TableCell({
  borders: cellBorders,
  width: { size: opts.width || 4680, type: WidthType.DXA },
  shading: opts.header ? { fill: NAVY, type: ShadingType.CLEAR } : undefined,
  margins: { top: 80, bottom: 80, left: 120, right: 120 },
  children: [new Paragraph({
    children: [new TextRun({
      text,
      bold: opts.header || opts.bold,
      color: opts.header ? "FFFFFF" : INK,
      size: opts.header ? 20 : 20,
    })],
  })],
});

const dataTable = (headers, rows) => {
  const colCount = headers.length;
  const totalWidth = 9360;
  const colW = Math.floor(totalWidth / colCount);
  return new Table({
    width: { size: totalWidth, type: WidthType.DXA },
    columnWidths: Array(colCount).fill(colW),
    rows: [
      new TableRow({
        children: headers.map(h => tCell(h, { header: true, width: colW })),
      }),
      ...rows.map(row => new TableRow({
        children: row.map(c => tCell(String(c), { width: colW })),
      })),
    ],
  });
};

// ─── Cover Page ─────────────────────────────────────────────────
const coverPage = [
  new Paragraph({ children: [], spacing: { before: 1200 } }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { before: 600, after: 200 },
    children: [new TextRun({
      text: "LAMASY",
      bold: true, size: 96, color: TEAL,
    })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: 800 },
    children: [new TextRun({
      text: "ERP MODERN UNTUK LAUNDRY UMKM INDONESIA",
      bold: true, size: 22, color: NAVY,
      characterSpacing: 80,
    })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: 200 },
    children: [new TextRun({
      text: "Dokumen Internal — Operasional & Teknis",
      size: 28, color: INK,
    })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({
      text: "Untuk tim teknis, ops, support, finance",
      italics: true, size: 22, color: ASH,
    })],
  }),
  new Paragraph({ children: [], spacing: { before: 2400 } }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Versi: Production — lamasy.harpy.id", size: 20, color: ASH })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Tanggal: 24 Juni 2026", size: 20, color: ASH })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Klasifikasi: INTERNAL — Confidential", size: 18, color: CORAL, bold: true })],
  }),
  new Paragraph({ children: [new PageBreak()] }),
];

// ─── Table of Contents ──────────────────────────────────────────
const tocSection = [
  h1("Daftar Isi"),
  new TableOfContents("Table of Contents", {
    hyperlink: true,
    headingStyleRange: "1-3",
  }),
  new Paragraph({ children: [new PageBreak()] }),
];

// ─── Content Sections ───────────────────────────────────────────
const content = [
  // ──── 1. Pengantar ────
  h1("1. Pengantar"),
  p("LAMASY adalah platform SaaS multi-tenant untuk operasional laundry, dirancang dari UMKM single-outlet sampai chain multi-outlet. Sistem dibagi tiga lapisan akses: Outlet (operasional harian), HQ (manajemen multi-outlet owner), dan SuperAdmin (admin platform LAMASY)."),
  callout("Dokumen ini ditujukan untuk tim internal — engineering, ops, finance, dan support. Berisi detail teknis, flow operasional, dan referensi sistem yang tidak akan dibagikan ke pihak eksternal."),

  // ──── 2. Arsitektur Global ────
  h1("2. Arsitektur Global"),
  h2("2.1 Model Multi-Tenant"),
  p("Sistem menggunakan model shared database + shared schema dengan scoping per tabel via kolom tenant_id (dan outlet_id untuk transaksional). Setiap tenant adalah satu bisnis laundry dengan satu owner dan satu atau lebih outlet. Outlet adalah lokasi fisik dengan operasional independen."),
  ...bullet([new TextRun({ text: "Tenant ", bold: true }), new TextRun("= bisnis laundry (1 owner, N outlet)")]),
  ...bullet([new TextRun({ text: "Outlet ", bold: true }), new TextRun("= lokasi fisik dengan operasional independen")]),
  ...bullet([new TextRun({ text: "Isolasi data ", bold: true }), new TextRun("enforced di query layer via TenantResolver + TenantQuery helper")]),
  ...bullet([new TextRun({ text: "103 tabel database ", bold: true }), new TextRun("mencakup operasional, finansial, HR, AI, dan platform")]),

  h2("2.2 Tech Stack"),
  dataTable(
    ["Layer", "Tech", "Catatan"],
    [
      ["Backend", "PHP 8 vanilla", "No framework, simple deployment"],
      ["Database", "MariaDB Hostinger", "Shared hosting, single instance"],
      ["Frontend", "Vanilla HTML/CSS/JS + Ponytail design system", "Tema dark navy + teal"],
      ["Auth", "Session + HMAC-SHA256 + CSRF", "Per-request token"],
      ["AI", "Anthropic Claude", "claude-sonnet-4-x, claude-haiku-4-5"],
      ["Email", "Hostinger SMTP via PHPMailer", "noreply@harpy.id"],
      ["WhatsApp", "Fonnte API", "1000 msg/bulan free tier"],
      ["PWA", "manifest + service worker", "Installable, offline-first cache"],
      ["Hosting", "Auto-deploy via git push", "~15 detik deploy time"],
      ["Timezone", "Asia/Jakarta (UTC+7)", "Critical untuk laporan harian"],
    ]
  ),

  h2("2.3 Folder Structure"),
  code("/                       — Outlet level pages (root)\n  /core/                — Reusable libraries (30 file)\n  /assets/banners/      — Banner image uploads (SA)\n  /api/                 — Internal AJAX endpoints\n  /hq/                  — HQ-level pages (30 file)\n  /superadmin/          — SuperAdmin pages (24 file)\n  /middleware/          — Auth/permission guards\n  /db/                  — Schema + migrations\n  /docs/                — Documentation\n  /master/              — Hostinger config (gitignored)"),

  h2("2.4 Permission Model — 3 Lapisan"),
  ...bullet([new TextRun({ text: "SuperAdmin", bold: true, color: TEAL_DEEP }), new TextRun(" — Founder + tim LAMASY. RBAC dengan 5 default role + 29 permissions.")]),
  ...bullet([new TextRun({ text: "HQ", bold: true, color: TEAL_DEEP }), new TextRun(" — Owner bisnis. Akses via hq.access permission.")]),
  ...bullet([new TextRun({ text: "Outlet", bold: true, color: TEAL_DEEP }), new TextRun(" — Kasir, kurir, staff. Role-based (owner, manager, kasir, kurir, produksi).")]),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 3. Flow Operasional ────
  h1("3. Flow Operasional", "flows"),
  p("Section ini menggambarkan 12 flow utama yang berjalan di sistem. Setiap flow include actor, langkah, dan data yang berubah."),

  h2("3.1 Tenant Onboarding Flow"),
  p("Dari calon customer datang sampai jadi tenant aktif yang transaksi:"),
  ...numbered_list([
    "Visitor buka lamasy.harpy.id → klik 'Daftar Gratis'",
    "Isi form /register.php (nama bisnis, owner, email, WA, password, nama outlet pertama)",
    "DB INSERT saas_tenants + hl_outlets + saas_users; auto-credit 10.000 coin trial",
    "SaNotifier::tenantRegistered → email ke SA dengan permission registrations.view",
    "Email verification link dikirim → tenant klik → status = trial",
    "Login pertama kali → /accept-tos.php → Splash tips + Product Tour walkthrough",
    "TenantProvisioner seed default data (layanan, parfum, bahan, COA)",
    "Transaksi pertama via POS → status = aktif",
    "Setelah 7 hari trial: top-up coin (tetap aktif) atau habis (grace 3 hari → suspended)",
  ]),

  h2("3.2 POS / Order Lifecycle"),
  p("Order dari penerimaan sampai customer pulang dengan cucian bersih:"),
  ...numbered_list([
    "Customer datang → kasir buka /pos.php",
    "Pilih customer existing atau input no.HP baru + parfum (opsional)",
    "Add layanan (cuci complete, setrika, dll). Bisa inline-create kalau belum ada.",
    "Tier express: Reguler / Express (+harga). Antar checkbox toggle ke kurir flow.",
    "Estimasi otomatis via applyMaxEstimasi() — max dari semua layanan + tier override",
    "Auto-apply promo aktif, cek saldo deposit + member tier discount",
    "Pembayaran: cash / transfer / deposit-deduct",
    "DB INSERT hl_order + hl_order_item + hl_pembayaran; status = 'diterima'",
    "Auto-deduct stok bahan (FinancialCalculator); auto-issue poin loyalty",
    "Cetak struk via StrukGenerator.php + QR code untuk track + portal",
    "Staff produksi scan QR di /produksi.php → 6 tahap dengan signature + foto",
    "Tahap: Terima → Sortir → Cuci → Setrika → Packing → Selesai",
    "Status order auto-update: diterima → diproses → siap",
    "Auto-send WA 'Cucian siap diambil'",
    "Customer ambil sendiri (mark 'selesai') atau via antar-jemput (flow 3.3)",
  ]),

  h2("3.3 Antar-Jemput Flow"),
  p("Order dengan pickup/delivery oleh kurir outlet:"),
  ...numbered_list([
    "POS dengan checkbox Antar=ON → auto-create hl_antar_jemput",
    "Type: pickup, delivery, atau both. Zona auto-detect dari alamat customer.",
    "Dispatcher buka /antar-jemput.php → assign kurir aktif",
    "Kurir terima notif WA 'Task baru: pickup dari Customer X'",
    "Kurir buka /kurir mobile → step buttons: Berangkat → Sampai → Pickup done",
    "Kembali outlet → produksi selesai → status order = siap",
    "Auto-create antar-jemput delivery (kalau type=both)",
    "Kurir delivery: step buttons + Done modal (foto bukti + customer signature)",
    "Status order = selesai, loyalty poin issued",
  ]),

  h2("3.4 Self-Service Mesin Flow"),
  ...numbered_list([
    "Customer datang ke outlet laundromat mode",
    "Scan QR mesin → /self.php?m=KODE (public, no login)",
    "Form: input HP + pilih cycle (cuci/keringkan/keduanya)",
    "Submit → DB transaction race-safe: SELECT FOR UPDATE → INSERT sesi → UPDATE mesin",
    "Customer mulai cycle → timer countdown",
    "Cycle selesai → status = 'selesai', alert di dashboard staff",
    "Staff verify + reset → status = 'tersedia'",
  ]),

  h2("3.5 Customer Portal Flow"),
  ...numbered_list([
    "Customer scan QR di struk → /p?t=<portal_token>",
    "Token validasi via hl_pelanggan.portal_token (UNIQUE, auto-gen)",
    "Set session pelanggan_id → redirect /pelanggan (read-only)",
    "Tab: Order Aktif / History / Hadiah / Saldo Deposit",
    "Member tier badge + poin balance + reward catalog",
    "Klik order detail → timeline status + item breakdown + estimasi",
    "Owner bisa regenerate token kalau leaked → token lama invalid",
  ]),
  callout("Portal customer READ-ONLY by design — tidak bisa edit/order untuk keamanan."),

  h2("3.6 Coin / Billing Flow"),
  ...numbered_list([
    "Tenant baru: auto-credit 10.000 coin (trial gratis 7 hari)",
    "Tenant transaksi normal: fitur basic gratis",
    "Tenant pakai fitur AI / premium: coin deducted per call (saas_coin_pricing)",
    "AIRateLimiter cek limit harian per fitur sebelum allow call",
    "Saldo coin < threshold (5.000): notif WA + email + banner dashboard tenant + alert SA",
    "Tenant top-up: buka /deposit → pilih package → upload bukti transfer",
    "SA Finance role approve di /superadmin/clients.php → UPDATE coin_balance + INSERT coin_ledger",
    "SaNotifier::paymentReceived → confirmation email ke tenant",
    "Kalau coin habis tanpa top-up: enter grace 3 hari → SaNotifier::trialExpiring",
    "Grace habis → status = suspended → block semua page kecuali billing + support",
    "Top-up → reactivate",
  ]),

  h2("3.7 AI Request Flow"),
  ...numbered_list([
    "User trigger AI feature (mis. HQ owner klik 'Generate Briefing')",
    "AIRateLimiter::checkAndIncrement → cek hl_ai_usage vs saas_coin_pricing.daily_limit",
    "Over limit → return error + block. OK → INSERT/UPDATE counter.",
    "Cache check (hl_ai_cache + Anthropic prompt cache 5-min TTL)",
    "Cache hit → return cached. Miss → call Claude API.",
    "AnthropicClient::send dengan AIPersona system prompt + data injection",
    "Response: log usage (tokens_in/out, cost_idr, cache_hit) + deduct coin",
    "INSERT hl_ai_usage + coin_ledger entry → return ke UI",
    "SA tracking di /superadmin/ai_usage.php: cost vs revenue, margin per fitur/tenant",
  ]),

  h2("3.8 Smartlink Migration Flow"),
  p("Tenant existing di kompetitor Smartlink pindah ke LAMASY dengan data Excel:"),
  ...numbered_list([
    "Tenant request migration ke SA",
    "SA buka /superadmin/migrations.php → 'New Migration Job'",
    "Upload file Excel hasil export Smartlink (2-tier header, multi-item nota)",
    "MigrationImporter::parseExcel → extract kolom + rows",
    "AI mapping via AIMigrationMapper (Claude analyze kolom Excel vs schema LAMASY)",
    "Cache template di hl_migration_mapping_templates untuk reuse",
    "SA review mapping → confirm/edit field assignment",
    "Dry run 10 rows pertama → cek correctness",
    "Confirm → batch INSERT hl_pelanggan, hl_order, hl_order_item",
    "Hitung kolom 'Total' (heuristic — handle biaya tambahan)",
    "Job logged di hl_migration_jobs dengan status success/partial",
  ]),
  callout("Migration upload tidak deduct coin — gratis service untuk acquisition."),

  h2("3.9 Support Ticket Flow"),
  ...numbered_list([
    "Tenant ada masalah → HQ owner buka /hq/support → 'New Ticket'",
    "Subject, body, attachment opsional → submit",
    "SaNotifier::supportTicketCreated → email ke SA dengan permission support.view",
    "SA Support staff buka /superadmin/support.php → inbox semua tickets",
    "Filter: open / replied / resolved → klik ticket → detail thread",
    "Reply (perm support.reply): tulis response → auto-send notif email + WA",
    "Multiple exchanges (thread)",
    "Mark closed (perm support.close): status = resolved → tenant bisa rate response",
  ]),

  h2("3.10 RBAC SuperAdmin Flow"),
  ...numbered_list([
    "Founder hire staff baru (mis. Support agent)",
    "Owner SA buka /superadmin/settings.php → tab 'Roles & Permissions'",
    "Pakai default 'support' role (6 perms) atau create custom",
    "Buka tab 'SA Team' → '+ Tambah' → input data + assign role",
    "DB INSERT super_admins + assign role_id",
    "Staff baru login → SaPermission::loadIntoSession() load perms dari junction table",
    "Setiap page SA: SaPermission::require('feature.action')",
    "Notif routing: SaNotifier::resolveRecipients($event) match permission",
    "Filter by notify_enabled + notif_events opt-in → send email (throttled 60s)",
  ]),

  h2("3.11 Multi-Outlet HQ Impersonate Flow"),
  ...numbered_list([
    "Owner buka /hq/dashboard → lihat 'Kesehatan Outlet' card",
    "Klik 'Masuk' per outlet → link include /switch-outlet?outlet=N&t=<HMAC-token>",
    "Token = hash_hmac('sha256', $outlet_id, $secret), validity ~60s",
    "Server verify token → set $_SESSION['outlet_id'] = N",
    "Redirect /dashboard outlet view → tenant_guard cek session",
    "Owner do anything outlet-level",
    "Switch ke HQ View → clear outlet_id session",
  ]),

  h2("3.12 Banner Carousel Flow"),
  ...numbered_list([
    "SA buka /superadmin/banners.php → '+ Tambah Banner'",
    "Pilih mode: Gambar (upload 1600×400 optimal, max 800 KB) atau Gradient",
    "Set: judul, deskripsi, CTA label+URL, icon emoji, schedule, urutan",
    "Save → INSERT saas_banners (image_url OR bg_gradient)",
    "Tenant buka /dashboard → BannerLoader::activeForTenant query banner aktif",
    "WHERE is_active=1 AND between starts_at/ends_at, LIMIT 5",
    "Render carousel: image mode bg-image + dark overlay; gradient CSS bg",
    "Auto-rotate setiap 6 detik + dots indicator",
  ]),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 4. Layer Outlet ────
  h1("4. Layer 1: Outlet (Operasional Harian)"),
  p("Audience: Kasir, kurir, staff produksi, manager outlet, owner. ~25 page di root project."),

  h2("4.1 Modul Transaksi & Order"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["pos.php", "POS kasir — input order baru"],
      ["orders.php", "Daftar order + filter status"],
      ["kanban.php", "Kanban view drag-drop status"],
      ["produksi.php", "Staff produksi — 6 stage workflow"],
      ["mesin.php", "Master mesin + monitor real-time"],
    ]
  ),

  h2("4.2 Antar-Jemput"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["antar-jemput.php", "Dispatcher view"],
      ["kurir.php", "Kurir mobile view (step-button + done modal)"],
      ["kurir-master.php", "CRUD kurir + account"],
    ]
  ),

  h2("4.3 Inventori & Master"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["inventori.php", "Stok bahan + mutasi + PO PDF"],
      ["layanan.php", "Master layanan + kategori + tier"],
      ["promo.php", "Promo dengan apply outlet"],
      ["customer.php", "Master customer"],
      ["member.php", "Member tier system"],
      ["deposit.php", "Wallet customer"],
      ["loyalty.php", "Sistem poin + reward"],
      ["retention.php", "Dormant customer"],
    ]
  ),

  h2("4.4 Customer-Facing Public"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["pelanggan.php", "Portal home"],
      ["pelanggan-order.php", "Order detail"],
      ["self.php", "Self-service mesin booking"],
      ["track.php / cek.php", "Public order tracking"],
      ["p.php", "Portal token entry"],
    ]
  ),

  h2("4.5 Kas, Keuangan & HR"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["kas.php", "Kas harian + mutasi"],
      ["laporan.php", "Laporan SAK EMKM (Neraca/LR/Arus Kas)"],
      ["piutang.php", "Piutang B2B"],
      ["karyawan.php", "Master karyawan"],
      ["absensi.php", "Clock-in/out"],
    ]
  ),

  h2("4.6 Role di Outlet"),
  dataTable(
    ["Role", "Akses"],
    [
      ["owner", "Full access semua fitur outlet"],
      ["manager", "Operasional + sebagian finansial"],
      ["kasir", "POS + customer + payment"],
      ["kurir", "Mobile view + task list (login redirect ke /kurir)"],
      ["produksi", "Produksi page only"],
    ]
  ),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 5. Layer HQ ────
  h1("5. Layer 2: HQ (Multi-Outlet Owner)"),
  p("Audience: Owner bisnis. Akses via hq.access permission. ~30 page di /hq/."),

  h2("5.1 Dashboard & Konsolidasi"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["dashboard.php", "Konsolidasi metrik semua outlet, kesehatan outlet, switch impersonate"],
      ["outlet.php", "CRUD outlet, setting antar-jemput, zona kurir"],
    ]
  ),

  h2("5.2 Finansial Konsolidasi"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["keuangan.php", "Laporan SAK EMKM gabungan"],
      ["laporan.php", "Custom report konsolidasi"],
      ["mutasi.php", "Transfer kas antar outlet"],
      ["billing.php", "Konsolidasi billing"],
      ["coin-info.php", "Info coin platform (saldo, history, top-up)"],
    ]
  ),

  h2("5.3 Operasional Lintas Outlet"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["inventori.php", "Stok konsolidasi + transfer antar outlet"],
      ["mesin.php", "Mesin konsolidasi + report utilization"],
      ["karyawan.php", "Semua karyawan lintas outlet"],
      ["penggajian.php", "HQ-level payroll"],
      ["pelanggan.php", "Customer lintas outlet"],
      ["droppoint.php", "Drop point manager"],
    ]
  ),

  h2("5.4 AI Tools (HQ-only)"),
  dataTable(
    ["Page", "Fungsi"],
    [
      ["ai-chat.php", "Owner tanya-jawab data natural language"],
      ["ai-churning.php", "Churn risk detection"],
    ]
  ),

  h2("5.5 HQ vs Outlet — Perbedaan"),
  dataTable(
    ["Aspect", "Outlet", "HQ"],
    [
      ["Scope data", "1 outlet", "semua outlet tenant"],
      ["Karyawan view", "yang assigned", "semua karyawan"],
      ["Finansial", "per-outlet", "konsolidasi"],
      ["Master data", "inherited dari HQ", "source of truth"],
      ["AI tools", "briefing outlet", "chat full + churn"],
      ["Switch outlet", "tidak bisa", "bisa impersonate"],
    ]
  ),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 6. Layer SA ────
  h1("6. Layer 3: SuperAdmin (Platform Admin)"),
  p("Audience: Founder + tim LAMASY (ops/finance/support/viewer). ~24 page di /superadmin/."),

  h2("6.1 Modul Utama"),
  dataTable(
    ["Kategori", "Pages", "Fungsi"],
    [
      ["Dashboard & Health", "dashboard, health", "Total tenant, revenue, churn, AI abuse alerts"],
      ["Client Management", "clients, client_detail, impersonate", "Tenant + outlet CRUD, suspend/topup"],
      ["Onboarding", "registrations, onboarding, migrations", "Pending queue + manual + Smartlink import"],
      ["Finance", "billing, payments, coin_pricing, packages", "Revenue tracking + pricing config"],
      ["AI", "ai_usage", "Cost vs revenue margin per fitur/tenant"],
      ["Communication", "support, announcements, broadcast, banners", "Tickets + mass comms + banners"],
      ["Settings", "settings (multi-tab)", "Maintenance, ToS, Tips, Notif, SA Team, Roles"],
    ]
  ),

  h2("6.2 RBAC SuperAdmin — 5 Default Role"),
  dataTable(
    ["Role", "Perm Count", "Untuk"],
    [
      ["owner", "29 (all)", "Founder Rizky"],
      ["ops", "7", "Registrasi + onboarding + impersonate"],
      ["finance", "10", "Billing + payments + pricing + packages"],
      ["support", "6", "Tickets + churn + broadcast"],
      ["viewer", "10", "Dashboard + metric read-only"],
    ]
  ),

  h2("6.3 29 Permissions (Sample)"),
  ...bullet("super_admins.manage — kelola SA team"),
  ...bullet("clients.view / clients.suspend / clients.topup"),
  ...bullet("support.view / support.reply / support.close"),
  ...bullet("billing.view / billing.refund"),
  ...bullet("coin_pricing.edit, packages.manage"),
  ...bullet("announcements.publish, banners.publish, broadcast.send"),
  ...bullet("registrations.view / registrations.approve, migrations.run"),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 7. AI Integration ────
  h1("7. Integrasi AI"),
  h2("7.1 9 Fitur AI yang Live"),
  dataTable(
    ["Fitur", "Modul", "Layer", "Pricing"],
    [
      ["AI Briefing harian", "dashboard widget", "Outlet + HQ", "varies"],
      ["AI Chat", "conversational data query", "HQ", "per-call"],
      ["AI Churning", "churn risk detection", "HQ", "per-call"],
      ["AI Insight", "pattern + anomaly", "HQ", "per-call"],
      ["AI Migration Mapper", "Excel column matching", "SA only", "free 1x"],
      ["AI Anomaly Alerts", "real-time transaction watch", "Outlet", "per-call"],
      ["AI Daily Report", "auto-generated summary", "HQ", "per-call"],
      ["AI Generate Nota", "smart nota template", "Outlet", "per-call"],
      ["AI Send WA", "AI-rewritten WA message", "Outlet", "per-call"],
    ]
  ),

  h2("7.2 Anti-Abuse: AIRateLimiter"),
  p("core/AIRateLimiter.php cek 3 hal sebelum allow call:"),
  ...bullet("DB-backed counter (hl_ai_usage per tenant + feature + date)"),
  ...bullet("Daily limit cek (saas_coin_pricing.daily_limit per fitur)"),
  ...bullet("Cost ceiling cek (cost yang akan terjadi vs sisa coin balance)"),
  callout("Tenant yang hit limit 3+ kali sehari → flagged di SA dashboard dengan violet AI shimmer banner."),

  h2("7.3 Tracking & Margin"),
  p("/superadmin/ai_usage.php menampilkan:"),
  ...bullet("Total AI calls + cache hit rate"),
  ...bullet("Total tokens in/out"),
  ...bullet("Cost Anthropic (actual API cost dalam IDR)"),
  ...bullet("Revenue Coin (coin spent × harga per coin)"),
  ...bullet("Net Margin per fitur + per tenant"),
  ...bullet("Trend harian (line chart cost vs revenue)"),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 8. Database Schema ────
  h1("8. Database Schema Overview"),
  p("103 tabel total, dikelompokkan berdasarkan domain."),

  h2("8.1 SaaS Platform Tables (saas_* / super_*)"),
  ...bullet("saas_tenants — tenant master + coin balance"),
  ...bullet("saas_users — user accounts per tenant"),
  ...bullet("saas_banners — banner carousel (gradient ATAU image)"),
  ...bullet("saas_coin_pricing — coin pack + AI limits"),
  ...bullet("saas_sa_notif_log — SA notification audit"),
  ...bullet("saas_packages, saas_pricing — subscription tiers"),
  ...bullet("super_admins — SA accounts dengan role_id"),
  ...bullet("sa_roles, sa_permissions, sa_role_permissions — RBAC junction"),

  h2("8.2 Tenant Operational (hl_*)"),
  p("Semua tabel hl_* scoped tenant_id (+ outlet_id untuk transaksional)."),
  h3("Master data"),
  ...bullet("hl_outlets, hl_layanan, hl_layanan_master, hl_parfum"),
  ...bullet("hl_bahan, hl_bahan_mutasi, hl_bahan_stok (view)"),
  ...bullet("hl_mesin, hl_mesin_cycle, hl_mesin_sesi"),
  ...bullet("hl_pelanggan, hl_pelanggan_member, hl_member_tier"),
  ...bullet("hl_drop_points, hl_kurir, hl_karyawan_outlet"),

  h3("Transaksional"),
  ...bullet("hl_order, hl_order_item, hl_order_notes"),
  ...bullet("hl_pembayaran"),
  ...bullet("hl_proses_input — produksi 6-stage audit"),
  ...bullet("hl_antar_jemput — pickup/delivery"),

  h3("Finansial"),
  ...bullet("hl_kas, hl_kas_bank, hl_kas_bank_mutasi"),
  ...bullet("hl_coa, hl_jurnal_manual"),
  ...bullet("hl_aset_tetap, hl_liabilitas, hl_piutang"),
  ...bullet("hl_laporan_cache"),

  h3("HR & Payroll"),
  ...bullet("hl_absensi, hl_izin"),
  ...bullet("hl_gaji, hl_gaji_komponen, hl_komisi_rekap"),
  ...bullet("hl_bonus_rule, hl_bonus_rule_outlet"),

  h3("Loyalty & Promo"),
  ...bullet("hl_promo, hl_promo_outlets"),
  ...bullet("hl_loyalty_log, hl_poin_reward, hl_poin_reward_outlet"),
  ...bullet("hl_deposit_topup, hl_deposit_usage, hl_deposit_refund, hl_deposit_bonus_tier"),
  ...bullet("hl_express_tier"),

  h3("AI Tracking"),
  ...bullet("hl_ai_usage — per-tenant per-feature daily counter"),
  ...bullet("hl_ai_cache — response cache"),
  ...bullet("hl_ai_outreach_log — AI-generated outreach"),

  h3("Lainnya"),
  ...bullet("hl_audit_log — semua action sensitif"),
  ...bullet("hl_login_attempts, hl_notif_log"),
  ...bullet("hl_broadcast, hl_broadcast_recipient"),
  ...bullet("hl_checklist_template, hl_checklist_submission"),
  ...bullet("hl_migration_jobs, hl_migration_mapping_templates"),
  ...bullet("hl_delete_request — UU PDP deletion requests"),
  ...bullet("hl_permissions, hl_splash_seen, hl_splash_tips"),
  ...bullet("coin_ledger, email_verifications"),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 9. Security & Compliance ────
  h1("9. Security & Compliance"),
  h2("9.1 Authentication"),
  ...bullet("Session-based dengan PHP native sessions"),
  ...bullet("Password hashed dengan bcrypt via password_hash()"),
  ...bullet("Login attempts logged di hl_login_attempts (brute-force protection)"),
  ...bullet("Email verification wajib sebelum aktif"),

  h2("9.2 Authorization"),
  ...bullet("CSRF token per request (HMAC-SHA256 signed)"),
  ...bullet("Tenant scoping enforced di TenantQuery::scoped()"),
  ...bullet("Permission check di setiap action via hl_permissions / SaPermission"),
  ...bullet("HQ guard via hq.access permission"),

  h2("9.3 Multi-Tenant Isolation"),
  ...bullet("Setiap query include WHERE tenant_id = $current_tenant"),
  ...bullet("Cross-tenant data access prevented at query layer (periodic audit)"),
  ...bullet("Outlet scoping pada transaksional tables"),

  h2("9.4 Compliance Indonesia"),
  ...bullet([new TextRun({ text: "UU PDP No 27/2022 ", bold: true }), new TextRun("— Personal Data Protection (delete request mechanism)")]),
  ...bullet([new TextRun({ text: "UU PK 8/1999 ", bold: true }), new TextRun("— Consumer Protection")]),
  ...bullet([new TextRun({ text: "UU ITE 11/2008 ", bold: true }), new TextRun("— Electronic Information & Transactions")]),
  ...bullet([new TextRun({ text: "SAK EMKM ", bold: true }), new TextRun("— laporan keuangan auto-generate compliant")]),

  h2("9.5 Audit Trail"),
  ...bullet("hl_audit_log — log semua action sensitif"),
  ...bullet("super_admin_audit — SA-level action log"),
  ...bullet("Authentication events, permission changes, financial transactions"),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 10. Komunikasi & Notif ────
  h1("10. Komunikasi & Notifikasi"),
  h2("10.1 Channels"),
  dataTable(
    ["Channel", "Provider", "Use Case", "Limit"],
    [
      ["Email", "Hostinger SMTP", "Verification, password reset, SA notif, broadcast", "—"],
      ["WhatsApp", "Fonnte API", "Order siap, kurir assignment, broadcast, low coin", "1000 msg/bulan free"],
      ["In-App", "Native", "Splash tips, banner carousel, Product Tour, alerts", "—"],
    ]
  ),

  h2("10.2 SA Notif Routing"),
  p("core/SaNotifier.php route notif berdasarkan permission match:"),
  ...bullet("6 event types: tenantRegistered, emailVerified, outletActivated, supportTicketCreated, trialExpiring, outletSuspended"),
  ...bullet("Filter recipient via SaPermission match per event type"),
  ...bullet("Per-SA opt-in via super_admins.notify_enabled + notif_events CSV"),
  ...bullet("Throttle 60-detik dedup per event_type untuk avoid spam"),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 11. Operasional ────
  h1("11. Operasional Tim"),
  h2("11.1 Deploy Workflow"),
  ...numbered_list([
    "Edit code lokal",
    "Test via MCP browser (Chrome extension) untuk verifikasi visual",
    "git commit + git push origin main",
    "Auto-deploy ke production ~15 detik",
    "Verify di production via MCP browser smoke test",
  ]),

  h2("11.2 DB Access untuk Tim"),
  ...bullet("Production DB via mysql client + ~/.my.cnf (kredensial Hostinger)"),
  ...bullet("Path: /opt/homebrew/opt/mysql-client/bin/mysql"),
  ...bullet("Backup: Hostinger automated daily backup"),

  h2("11.3 Master Credentials"),
  ...bullet("master/config/db.php — gitignored, never commit"),
  ...bullet("SuperAdmin Rizky — owner role, full access"),
  ...bullet("Fonnte API key + Anthropic API key di env config"),

  h2("11.4 Monitoring & Health"),
  ...bullet("/superadmin/health.php — real-time platform health"),
  ...bullet("WA delivery rate alert (di bawah 95% trigger banner)"),
  ...bullet("Error log via core/ErrorLogger.php"),
  ...bullet("AI usage abuse detection"),

  h2("11.5 Common Operasional Tasks"),
  dataTable(
    ["Task", "Lokasi"],
    [
      ["Onboard tenant baru", "/superadmin/registrations.php"],
      ["Top up coin tenant", "/superadmin/clients.php → Topup"],
      ["Suspend tenant", "/superadmin/clients.php → Suspend"],
      ["Migrasi Excel Smartlink", "/superadmin/migrations.php"],
      ["Reply support ticket", "/superadmin/support.php"],
      ["Broadcast pengumuman", "/superadmin/announcements.php"],
      ["Update banner dashboard", "/superadmin/banners.php"],
      ["Add SA team member", "/superadmin/settings.php → SA Team"],
      ["Toggle maintenance mode", "/superadmin/settings.php → Maintenance"],
      ["Cek AI usage + margin", "/superadmin/ai_usage.php"],
    ]
  ),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 12. Roadmap ────
  h1("12. Roadmap Internal"),
  h2("12.1 High Priority"),
  ...bullet([new TextRun({ text: "2FA TOTP ", bold: true }), new TextRun("untuk SuperAdmin accounts — security hardening")]),
  ...bullet([new TextRun({ text: "Audit Log Viewer ", bold: true }), new TextRun("terdedicated untuk SA — searchable + exportable")]),
  ...bullet([new TextRun({ text: "Extend Trial Manual + Manual Coin Adjustment ", bold: true }), new TextRun("UI di SA — operational efficiency")]),
  ...bullet([new TextRun({ text: "Email Template Editor ", bold: true }), new TextRun("— jangan hardcode template di Mailer.php")]),

  h2("12.2 On Hold"),
  ...bullet([new TextRun({ text: "Native app ", bold: true }), new TextRun("(Capacitor thin shell + remote webview) — hold sampai PWA feature-complete")]),
  ...bullet([new TextRun({ text: "QRIS + WhatsApp Business API ", bold: true }), new TextRun("— hold separately, evaluasi business case dulu")]),

  h2("12.3 Exploratory"),
  ...bullet("Lighthouse baseline metrics untuk measure improvement"),
  ...bullet("Cloudflare Analytics integration"),
  ...bullet("CF panel optimization: Auto-Minify + Brotli + Tiered Cache (manual setting)"),

  new Paragraph({ children: [new PageBreak()] }),

  // ──── 13. Design System ────
  h1("13. Design System"),
  h2("13.1 Outlet + HQ — Ponytail"),
  dataTable(
    ["Token", "Value", "Usage"],
    [
      ["Background", "Dark navy #0F1C3A", "Page background"],
      ["Brand Accent", "Teal #35E8D5", "Primary CTA, active state, brand"],
      ["Type", "Plus Jakarta Sans + DM Mono", "Body + tabular"],
      ["Vibe", "Operational ERP", "Mobile-first, PWA-ready"],
    ]
  ),

  h2("13.2 SuperAdmin — AI Command Center"),
  dataTable(
    ["Token", "Value", "Usage"],
    [
      ["Background", "Obsidian #0A0F1F + radial gradient + dot-grid", "Page bg"],
      ["Surface", "Slate #141B2D dengan glass blur", "Cards"],
      ["Brand Accent", "Teal #35E8D5", "CTA, active state"],
      ["AI Accent", "Violet #A78BFA", "AI-specific features semantic"],
      ["Type", "Inter + Inter Tight + JetBrains Mono", "Body + display + data"],
      ["Signature", "AI Shimmer (teal→violet animated)", "Borders, pills, strips"],
      ["Vibe", "Premium AI tooling", "Modern dashboard"],
    ]
  ),

  // ──── 14. Numbers ────
  h1("14. Stats Sistem"),
  dataTable(
    ["Metric", "Count"],
    [
      ["DB Tables", "103"],
      ["PHP files (Outlet + Public)", "61"],
      ["PHP files (HQ)", "30"],
      ["PHP files (SuperAdmin)", "24"],
      ["Core Libraries", "30"],
      ["AI Features Integrated", "9"],
      ["Default SA Roles", "5"],
      ["SA Permissions", "29"],
      ["Auto-deploy Time", "~15 detik"],
    ]
  ),

  new Paragraph({ spacing: { before: 600 }, children: [] }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "— End of Internal Document —", italics: true, color: ASH })],
  }),
];

// helper that wasn't defined yet (needed by content above)
function numbered_list(items) {
  return items.map(text => new Paragraph({
    numbering: { reference: "numbers", level: 0 },
    children: typeof text === 'string' ? [new TextRun(text)] : text,
  }));
}

// ─── Document Build ─────────────────────────────────────────────
const doc = new Document({
  creator: "LAMASY",
  title: "LAMASY — Internal Document",
  description: "Internal technical & operational documentation",
  styles: {
    default: { document: { run: { font: "Arial", size: 22 } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 36, bold: true, font: "Arial", color: NAVY },
        paragraph: { spacing: { before: 360, after: 200 }, outlineLevel: 0,
          border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: TEAL, space: 4 } } } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 28, bold: true, font: "Arial", color: NAVY },
        paragraph: { spacing: { before: 260, after: 120 }, outlineLevel: 1 } },
      { id: "Heading3", name: "Heading 3", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 24, bold: true, font: "Arial", color: TEAL_DEEP },
        paragraph: { spacing: { before: 180, after: 80 }, outlineLevel: 2 } },
    ],
  },
  numbering: {
    config: [
      { reference: "bullets",
        levels: [
          { level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT,
            style: { paragraph: { indent: { left: 720, hanging: 360 } } } },
          { level: 1, format: LevelFormat.BULLET, text: "◦", alignment: AlignmentType.LEFT,
            style: { paragraph: { indent: { left: 1440, hanging: 360 } } } },
        ] },
      { reference: "numbers",
        levels: [
          { level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT,
            style: { paragraph: { indent: { left: 720, hanging: 360 } } } },
        ] },
    ],
  },
  sections: [{
    properties: {
      page: {
        size: { width: 11906, height: 16838 }, // A4
        margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 },
      },
    },
    headers: {
      default: new Header({
        children: [new Paragraph({
          tabStops: [{ type: TabStopType.RIGHT, position: 9026 }],
          children: [
            new TextRun({ text: "LAMASY", bold: true, color: TEAL_DEEP, size: 18 }),
            new TextRun({ text: " · Internal Document", color: ASH, size: 18 }),
            new TextRun({ text: "\tCONFIDENTIAL", color: CORAL, size: 18, bold: true }),
          ],
          border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: CREASE, space: 4 } },
        })],
      }),
    },
    footers: {
      default: new Footer({
        children: [new Paragraph({
          tabStops: [{ type: TabStopType.RIGHT, position: 9026 }],
          alignment: AlignmentType.LEFT,
          children: [
            new TextRun({ text: "Versi Production · lamasy.harpy.id", color: ASH, size: 16 }),
            new TextRun({ text: "\tHalaman ", color: ASH, size: 16 }),
            new TextRun({ children: [PageNumber.CURRENT], color: ASH, size: 16 }),
            new TextRun({ text: " dari ", color: ASH, size: 16 }),
            new TextRun({ children: [PageNumber.TOTAL_PAGES], color: ASH, size: 16 }),
          ],
        })],
      }),
    },
    children: [...coverPage, ...tocSection, ...content],
  }],
});

Packer.toBuffer(doc).then(buffer => {
  fs.writeFileSync("/Users/rizky/Documents/lamasy/docs/exports/LAMASY-Internal-Document.docx", buffer);
  console.log("✓ Internal document created: LAMASY-Internal-Document.docx");
});
