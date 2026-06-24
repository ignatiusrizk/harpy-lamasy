// LAMASY — Investor Pitch Document Generator
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
const NAVY_DEEP = "0A0F1F";
const VIOLET = "A78BFA";
const ASH = "6B7280";
const CREASE = "E5E7EB";
const SAGE = "84CC16";
const CORAL = "F43F5E";
const AMBER = "F59E0B";
const INK = "1A1F2E";

// ─── Helpers ────────────────────────────────────────────────────
const p = (text, opts = {}) => new Paragraph({
  spacing: { after: 120, line: 360 },
  children: typeof text === 'string'
    ? [new TextRun({ text, ...opts.run })]
    : text,
  ...opts.para,
});

const lead = (text) => new Paragraph({
  spacing: { before: 120, after: 240, line: 400 },
  children: [new TextRun({ text, size: 26, color: INK })],
});

const h1 = (text) => new Paragraph({
  heading: HeadingLevel.HEADING_1,
  spacing: { before: 480, after: 200 },
  children: [new TextRun(text)],
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

const bullet = (text) => new Paragraph({
  numbering: { reference: "bullets", level: 0 },
  spacing: { after: 80 },
  children: typeof text === 'string' ? [new TextRun(text)] : text,
});

const numbered = (text) => new Paragraph({
  numbering: { reference: "numbers", level: 0 },
  spacing: { after: 80 },
  children: typeof text === 'string' ? [new TextRun(text)] : text,
});

const stat = (number, label, color = TEAL_DEEP) => new Paragraph({
  alignment: AlignmentType.CENTER,
  spacing: { before: 120, after: 60 },
  children: [
    new TextRun({ text: number, size: 72, bold: true, color }),
    new TextRun({ text: "\n", break: 1 }),
    new TextRun({ text: label, size: 18, color: ASH, characterSpacing: 40 }),
  ],
});

const callout = (text, type = "teal") => {
  const colors = {
    teal: { bg: "E6FCF9", border: TEAL_DEEP },
    violet: { bg: "F4F0FE", border: VIOLET },
    amber: { bg: "FEF3C7", border: AMBER },
    sage: { bg: "ECFCCB", border: SAGE },
  };
  const c = colors[type];
  return new Paragraph({
    shading: { fill: c.bg, type: ShadingType.CLEAR },
    border: { left: { style: BorderStyle.SINGLE, size: 24, color: c.border, space: 12 } },
    spacing: { before: 160, after: 160 },
    children: typeof text === 'string'
      ? [new TextRun({ text, italics: true, color: INK, size: 22 })]
      : text,
  });
};

const tableBorder = { style: BorderStyle.SINGLE, size: 4, color: CREASE };
const cellBorders = { top: tableBorder, bottom: tableBorder, left: tableBorder, right: tableBorder };

const tCell = (text, opts = {}) => new TableCell({
  borders: cellBorders,
  width: { size: opts.width || 4680, type: WidthType.DXA },
  shading: opts.header
    ? { fill: NAVY, type: ShadingType.CLEAR }
    : opts.highlight
      ? { fill: "E6FCF9", type: ShadingType.CLEAR }
      : undefined,
  margins: { top: 100, bottom: 100, left: 140, right: 140 },
  children: [new Paragraph({
    children: [new TextRun({
      text,
      bold: opts.header || opts.bold,
      color: opts.header ? "FFFFFF" : INK,
      size: opts.header ? 20 : 21,
    })],
  })],
});

const dataTable = (headers, rows, highlightCol = -1) => {
  const colCount = headers.length;
  const totalWidth = 9026;
  const colW = Math.floor(totalWidth / colCount);
  return new Table({
    width: { size: totalWidth, type: WidthType.DXA },
    columnWidths: Array(colCount).fill(colW),
    rows: [
      new TableRow({
        tableHeader: true,
        children: headers.map(h => tCell(h, { header: true, width: colW })),
      }),
      ...rows.map(row => new TableRow({
        children: row.map((c, idx) => tCell(String(c), { width: colW, highlight: idx === highlightCol })),
      })),
    ],
  });
};

// ─── Cover Page ─────────────────────────────────────────────────
const coverPage = [
  new Paragraph({ spacing: { before: 800 }, children: [] }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "LAMASY", bold: true, size: 144, color: TEAL })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { before: 200, after: 480 },
    children: [new TextRun({
      text: "ERP MODERN UNTUK LAUNDRY UMKM INDONESIA",
      bold: true, size: 24, color: NAVY, characterSpacing: 100,
    })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: 600 },
    children: [new TextRun({
      text: "AI-First. Multi-Outlet. Indonesia-Made.",
      italics: true, size: 32, color: TEAL_DEEP,
    })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { before: 800 },
    children: [new TextRun({
      text: "Investor Brief", bold: true, size: 36, color: INK,
    })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: 1600 },
    children: [new TextRun({
      text: "Seed Round · 2026", size: 24, color: ASH,
    })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { before: 1200 },
    children: [new TextRun({ text: "Ignatius Rizky", size: 22, bold: true, color: INK })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Founder & CEO", size: 20, color: ASH })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "rizkyignatius@gmail.com · lamasy.harpy.id", size: 18, color: ASH })],
  }),
  new Paragraph({ children: [new PageBreak()] }),
];

// ─── Content ────────────────────────────────────────────────────
const content = [
  // ═══════════ EXECUTIVE SUMMARY ═══════════
  h1("Executive Summary"),
  lead("LAMASY adalah platform SaaS ERP modern untuk industri laundry di Indonesia — sektor yang growth tinggi tapi underserved oleh teknologi enterprise."),

  p("Kami menawarkan operasional end-to-end dalam satu platform: POS, produksi, antar-jemput, inventori, keuangan compliant SAK EMKM, plus integrasi AI yang menjadikan kami ERP pertama di Indonesia yang AI-first untuk segmen laundry."),

  callout("Kompetitor utama (Smartlink, Cuci.Co) belum integrasi AI. LAMASY membangun moat lewat AI-driven operations: briefing harian otomatis, churn detection, anomaly alerts, dan natural-language data chat — semua dengan business model pay-as-you-use coin.", "teal"),

  h2("Highlight Investasi"),
  bullet([new TextRun({ text: "Pasar besar dan terfragmentasi: ", bold: true }), new TextRun("~50.000+ usaha laundry di Indonesia, mayoritas masih spreadsheet/manual.")]),
  bullet([new TextRun({ text: "Product live & functional: ", bold: true }), new TextRun("Platform sudah jalan di lamasy.harpy.id dengan tenant aktif transaksi.")]),
  bullet([new TextRun({ text: "AI-first differentiation: ", bold: true }), new TextRun("9 fitur AI live (briefing, chat, churn, anomaly, dll) — moat sulit di-replikasi.")]),
  bullet([new TextRun({ text: "Multi-tenant scalable: ", bold: true }), new TextRun("Single platform handle 1 owner sampai chain N-outlet dengan HQ konsolidasi.")]),
  bullet([new TextRun({ text: "Indonesia-native: ", bold: true }), new TextRun("UU PDP compliant, SAK EMKM laporan, Bahasa Indonesia interface, Fonnte WA integration.")]),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ THE PROBLEM ═══════════
  h1("The Problem"),
  lead("Bisnis laundry UMKM di Indonesia operate dengan tools yang fragmented, manual, dan tidak scale."),

  h2("Pain Points yang Kami Selesaikan"),

  h3("1. Operasional masih manual"),
  p("Mayoritas laundry pakai buku, WhatsApp, dan spreadsheet untuk tracking order. Lupa cucian, hilang nota, miscommunication staff → customer complain."),

  h3("2. Multi-outlet tanpa konsolidasi"),
  p("Owner punya 2-3 outlet harus check satu-satu. Tidak ada dashboard konsolidasi keuangan/stok/karyawan. Sulit ambil keputusan data-driven."),

  h3("3. Tidak ada integrasi self-service customer"),
  p("Customer harus telepon untuk cek status. Tidak ada portal untuk lihat poin loyalty, saldo deposit, atau history. Friction tinggi."),

  h3("4. Antar-jemput belum digital"),
  p("Kurir lewat WA grup, sulit assign + track. Tidak ada bukti delivery (foto + signature)."),

  h3("5. Laporan keuangan tidak compliant"),
  p("Mayoritas laundry tidak punya laporan SAK EMKM proper → masalah saat lapor pajak / akses pinjaman bank."),

  h3("6. Tidak ada AI insight"),
  p("Owner pegang data ribuan transaksi tapi tidak tahu apa yang penting. Pattern customer, anomali transaksi, churn risk — semua invisible."),

  callout([
    new TextRun({ text: "Solusi parsial yang ada (Smartlink, Cuci.Co): ", bold: true, color: INK, size: 22 }),
    new TextRun({ text: "address operasional tapi tanpa AI, tanpa multi-tenant proper, dan UI yang outdated. LAMASY membangun next-generation experience.", italics: true, color: INK, size: 22 }),
  ], "amber"),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ MARKET OPPORTUNITY ═══════════
  h1("Market Opportunity"),

  h2("Market Size Indonesia"),
  dataTable(
    ["Metric", "Estimasi", "Sumber"],
    [
      ["Total usaha laundry Indonesia", "~50.000+", "Industry estimates"],
      ["Pertumbuhan industri YoY", "12-15%", "Asosiasi Laundry"],
      ["TAM (Total Addressable Market)", "~Rp 200 M/tahun ARR", "@ Rp 4 jt/tenant/tahun"],
      ["SAM (Serviceable Addressable)", "~Rp 60 M/tahun", "30% market reachable"],
      ["SOM (3-tahun realistic)", "~Rp 6 M/tahun", "10% SAM penetration"],
    ]
  ),

  h2("Mengapa Sekarang"),
  bullet([new TextRun({ text: "AI accessible & affordable: ", bold: true }), new TextRun("Claude API mature, harga turun → bisa build AI features dengan margin profit.")]),
  bullet([new TextRun({ text: "Post-COVID acceleration: ", bold: true }), new TextRun("UMKM Indonesia mulai aware digitalisasi setelah COVID-19.")]),
  bullet([new TextRun({ text: "UU PDP enforcement: ", bold: true }), new TextRun("UU 27/2022 efektif → tenant butuh platform compliant untuk customer data.")]),
  bullet([new TextRun({ text: "SAK EMKM mandatory: ", bold: true }), new TextRun("Tax compliance push laundry untuk pakai system proper.")]),
  bullet([new TextRun({ text: "Mobile payment matang: ", bold: true }), new TextRun("QRIS adoption tinggi → integrasi cashless friction-low.")]),

  h2("Kompetitor"),
  dataTable(
    ["Kompetitor", "Approach", "AI?", "Multi-outlet?", "Kelemahan"],
    [
      ["Smartlink", "Desktop legacy", "❌", "Limited", "UI outdated, Excel-based"],
      ["Cuci.Co", "Web SaaS", "❌", "Basic", "No AI, no integrasi mendalam"],
      ["DIY Spreadsheet", "Excel/Google Sheets", "❌", "No", "Manual, error-prone"],
      ["WhatsApp + Buku", "Manual", "❌", "No", "Hilang data, tidak scalable"],
      ["LAMASY", "AI-first SaaS", "✓ 9 fitur", "✓ HQ console", "—"],
    ],
    4  // highlight last column
  ),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ SOLUTION ═══════════
  h1("Solution: LAMASY Platform"),
  lead("Three-layer SaaS platform yang serve dari kasir frontline sampai founder LAMASY platform."),

  h2("Arsitektur"),
  p("LAMASY dirancang 3-lapisan akses, masing-masing dengan UI yang dioptimalkan untuk audience-nya:"),

  bullet([new TextRun({ text: "Outlet ", bold: true, color: TEAL_DEEP }), new TextRun("(operasional harian) — kasir, kurir, staff produksi. ~25 page. Mobile-first PWA.")]),
  bullet([new TextRun({ text: "HQ ", bold: true, color: TEAL_DEEP }), new TextRun("(multi-outlet owner) — konsolidasi metrik + AI tools premium. ~30 page.")]),
  bullet([new TextRun({ text: "SuperAdmin ", bold: true, color: TEAL_DEEP }), new TextRun("(platform admin LAMASY) — tenant management + billing + support. ~24 page dengan AI Command Center design.")]),

  h2("Core Modules"),

  h3("Operasional"),
  bullet("POS dengan layanan inline-create + estimasi otomatis"),
  bullet("Produksi 6-tahap dengan signature canvas + foto + scan QR"),
  bullet("Antar-jemput dengan kurir mobile app (foto + signature delivery proof)"),
  bullet("Self-service mesin untuk laundromat (customer scan QR → booking sendiri)"),
  bullet("Inventori dengan auto-deduct bahan + alert stok kritis + PO PDF"),

  h3("Customer-Centric"),
  bullet("Portal pelanggan via QR di struk (read-only, secure)"),
  bullet("Public order tracking tanpa login"),
  bullet("Member tier system (Bronze/Silver/Gold/VIP) dengan auto-promotion"),
  bullet("Sistem poin + reward catalog"),
  bullet("Deposit wallet untuk customer regular"),

  h3("Keuangan"),
  bullet("Kas harian per outlet + mutasi antar kas"),
  bullet("Aset tetap + liabilitas + jurnal manual"),
  bullet("Laporan SAK EMKM compliant: Neraca, Laba Rugi, Arus Kas (auto-generate)"),
  bullet("Piutang B2B tracking"),

  h3("HR & Payroll"),
  bullet("Master karyawan + assign ke outlet"),
  bullet("Absensi (clock in/out)"),
  bullet("Penggajian dengan komponen custom"),
  bullet("Komisi rekap per karyawan"),
  bullet("Bonus rule target-based per outlet"),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ AI DIFFERENTIATION ═══════════
  h1("AI Differentiation"),
  lead("9 fitur AI live yang membuat LAMASY ERP pertama di Indonesia dengan AI deep integration untuk laundry."),

  callout([
    new TextRun({ text: "Positioning: ", bold: true, color: INK, size: 22 }),
    new TextRun({ text: "ERP modern dengan integrasi AI pertama di Indonesia untuk laundry SME.", italics: true, color: INK, size: 22 }),
  ], "violet"),

  h2("9 AI Features Live"),
  dataTable(
    ["Fitur", "Value untuk Tenant", "Tech"],
    [
      ["AI Briefing Harian", "Auto-summary kinerja hari, save 15 mnt/hari", "Claude Sonnet"],
      ["AI Chat", "Owner tanya data natural language", "Claude Sonnet"],
      ["AI Churning", "Deteksi customer mau hilang sebelum hilang", "Claude Haiku"],
      ["AI Insight", "Pattern + anomaly detection", "Claude Sonnet"],
      ["AI Migration Mapper", "Import Excel kompetitor → mapping otomatis", "Claude Sonnet"],
      ["AI Anomaly Alerts", "Real-time transaction watch (fraud detect)", "Claude Haiku"],
      ["AI Daily Report", "Auto-generated daily report HQ", "Claude Sonnet"],
      ["AI Generate Nota", "Smart nota template generation", "Claude Haiku"],
      ["AI Send WA", "AI-rewrite WA message ke customer", "Claude Haiku"],
    ]
  ),

  h2("Defensibility / Moat"),
  bullet([new TextRun({ text: "Data flywheel: ", bold: true }), new TextRun("Semakin banyak tenant, semakin banyak data → AI cache hit rate naik, cost turun, margin naik.")]),
  bullet([new TextRun({ text: "AI usage tracking sophisticated: ", bold: true }), new TextRun("Per-feature daily limit, cost vs revenue per tenant — kompetitor harus build dari scratch.")]),
  bullet([new TextRun({ text: "Pricing innovation: ", bold: true }), new TextRun("Coin-based pay-as-you-use untuk AI features — fairness tinggi, transparent.")]),
  bullet([new TextRun({ text: "Brand association: ", bold: true }), new TextRun("First-mover di Indonesia untuk 'AI ERP laundry' — defensible positioning.")]),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ BUSINESS MODEL ═══════════
  h1("Business Model"),

  h2("Revenue Streams"),

  h3("1. Setup Fee (One-time)"),
  p("Onboarding tenant baru dengan provisioning, data migration assistance, training. Berkisar Rp 500K – Rp 2 jt tergantung kompleksitas (single outlet vs chain)."),

  h3("2. Subscription Tier (Recurring)"),
  dataTable(
    ["Tier", "Harga/Bulan", "Outlet Limit", "Coin Included"],
    [
      ["Starter", "Rp 99.000", "1 outlet", "1.000 coin"],
      ["Growth", "Rp 249.000", "3 outlet", "5.000 coin"],
      ["Chain", "Rp 599.000", "Unlimited", "20.000 coin"],
      ["Enterprise", "Custom", "Unlimited + SLA", "Custom"],
    ]
  ),
  new Paragraph({ children: [new TextRun({ text: "*Pricing indicative, subject to market validation.", italics: true, color: ASH, size: 20 })] }),

  h3("3. Coin Top-Up (Usage-Based)"),
  p("Coin extra untuk fitur AI premium beyond subscription quota. Misal: AI Chat 50 coin/query, AI Briefing 10 coin/generate."),
  dataTable(
    ["Pack", "Coin", "Harga", "Per-Coin"],
    [
      ["Starter Pack", "5.000", "Rp 50.000", "Rp 10"],
      ["Popular Pack", "12.000", "Rp 100.000", "Rp 8,3"],
      ["Bulk Pack", "30.000", "Rp 200.000", "Rp 6,7"],
    ]
  ),

  h2("Unit Economics (Projected)"),
  dataTable(
    ["Metric", "Starter", "Growth", "Chain"],
    [
      ["MRR per tenant", "Rp 99K", "Rp 249K", "Rp 599K"],
      ["Setup Fee", "Rp 500K", "Rp 1 jt", "Rp 2 jt"],
      ["Coin top-up avg", "Rp 25K/bln", "Rp 75K/bln", "Rp 200K/bln"],
      ["ARPU monthly", "Rp 124K", "Rp 324K", "Rp 799K"],
      ["Gross margin", "78%", "82%", "85%"],
    ]
  ),

  callout([
    new TextRun({ text: "Strategi: ", bold: true, color: INK, size: 22 }),
    new TextRun({ text: "AI sebagai growth engine — bukan cost center. Coin top-up margin 70-80% setelah Anthropic API cost. Higher engagement = higher coin spend = higher revenue.", italics: true, color: INK, size: 22 }),
  ], "sage"),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ TRACTION ═══════════
  h1("Traction & Current State"),

  h2("Product Maturity"),
  callout([
    new TextRun({ text: "✓ Platform LIVE di production ", bold: true, color: TEAL_DEEP, size: 24 }),
    new TextRun({ text: "(lamasy.harpy.id) dengan tenant transaksi aktif.", color: INK, size: 22 }),
  ], "teal"),

  h2("Technical Stats"),
  dataTable(
    ["Component", "Count", "Notes"],
    [
      ["Database Tables", "103", "Comprehensive coverage"],
      ["Outlet Pages", "~25", "Operasional harian"],
      ["HQ Pages", "~30", "Multi-outlet management"],
      ["SuperAdmin Pages", "~24", "Platform admin"],
      ["Core Libraries", "30", "Reusable PHP classes"],
      ["AI Features", "9", "Production-grade"],
      ["RBAC Roles (default)", "5", "+ custom roles"],
      ["RBAC Permissions", "29", "Granular per module"],
      ["Auto-deploy Time", "~15 detik", "git push to live"],
    ]
  ),

  h2("What's Already Built"),
  bullet([new TextRun({ text: "✓ End-to-end POS + produksi + antar-jemput", bold: true })]),
  bullet([new TextRun({ text: "✓ Multi-outlet HQ konsolidasi dengan impersonate", bold: true })]),
  bullet([new TextRun({ text: "✓ Customer portal + public tracking + self-service mesin", bold: true })]),
  bullet([new TextRun({ text: "✓ Laporan keuangan SAK EMKM compliant auto-generate", bold: true })]),
  bullet([new TextRun({ text: "✓ 9 AI features production dengan rate limiting + cache + cost tracking", bold: true })]),
  bullet([new TextRun({ text: "✓ SuperAdmin dashboard + RBAC + billing + support ticket system", bold: true })]),
  bullet([new TextRun({ text: "✓ Smartlink (kompetitor) Excel migration dengan AI mapping", bold: true })]),
  bullet([new TextRun({ text: "✓ PWA installable + mobile-first responsive design", bold: true })]),
  bullet([new TextRun({ text: "✓ UU PDP + SAK EMKM + UU ITE compliance", bold: true })]),

  h2("Competitive Position"),
  dataTable(
    ["Capability", "Smartlink", "Cuci.Co", "LAMASY"],
    [
      ["AI integration", "❌", "❌", "✓ 9 fitur"],
      ["Multi-outlet HQ console", "Limited", "Basic", "✓ Full"],
      ["Customer portal", "❌", "Basic", "✓ Full"],
      ["Antar-jemput integrated", "❌", "Limited", "✓ Mobile app"],
      ["SAK EMKM auto-report", "❌", "❌", "✓"],
      ["Self-service mesin", "❌", "❌", "✓"],
      ["Smartlink migration tool", "—", "❌", "✓ AI-mapped"],
      ["Modern UI", "❌", "Outdated", "✓"],
      ["PWA mobile", "❌", "❌", "✓"],
    ]
  ),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ ROADMAP ═══════════
  h1("Roadmap & Vision"),

  h2("Next 6 Months (Post-Seed)"),
  bullet([new TextRun({ text: "Sales & Marketing: ", bold: true }), new TextRun("Target acquisition 100+ tenant aktif via direct sales + content marketing + komunitas asosiasi laundry.")]),
  bullet([new TextRun({ text: "Mobile Native: ", bold: true }), new TextRun("Launch native app (Capacitor wrapper) untuk PlayStore + AppStore presence.")]),
  bullet([new TextRun({ text: "Payment Gateway: ", bold: true }), new TextRun("QRIS integration + Midtrans untuk auto-topup coin tanpa friction.")]),
  bullet([new TextRun({ text: "WhatsApp Business API: ", bold: true }), new TextRun("Upgrade dari Fonnte ke WA Business API untuk volume + reliability.")]),
  bullet([new TextRun({ text: "2FA + Audit Viewer: ", bold: true }), new TextRun("Security hardening untuk enterprise-ready posture.")]),

  h2("12-18 Months Vision"),
  bullet([new TextRun({ text: "Marketplace ekspansi: ", bold: true }), new TextRun("Service marketplace di portal customer (cross-sell antar laundry).")]),
  bullet([new TextRun({ text: "AI Agent autonomous: ", bold: true }), new TextRun("AI yang bisa execute action — auto-reply WA, auto-create promo, auto-flag refund.")]),
  bullet([new TextRun({ text: "Embedded finance: ", bold: true }), new TextRun("Pinjaman modal kerja untuk tenant berdasarkan revenue data (partnership fintech).")]),
  bullet([new TextRun({ text: "Vertical expansion: ", bold: true }), new TextRun("Adaptasi platform untuk vertikal serupa: salon, klinik kecantikan, jasa kelola pakaian premium.")]),
  bullet([new TextRun({ text: "Regional expansion: ", bold: true }), new TextRun("Setelah dominasi Indonesia, ekspansi ke Vietnam/Filipina (laundry biz pattern serupa).")]),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ TEAM ═══════════
  h1("Team"),

  h2("Founder"),
  new Paragraph({
    spacing: { before: 200, after: 120 },
    children: [
      new TextRun({ text: "Ignatius Rizky", bold: true, size: 28, color: INK }),
      new TextRun({ text: "  ·  ", color: ASH, size: 24 }),
      new TextRun({ text: "Founder & CEO", italics: true, color: TEAL_DEEP, size: 22 }),
    ],
  }),
  p("Bertanggung jawab atas product, engineering, dan business strategy. Building LAMASY dari scratch sebagai solo founder dengan AI-assisted development workflow."),

  h2("Skills & Background"),
  bullet("Full-stack engineering: PHP, JavaScript, MariaDB, system architecture"),
  bullet("Domain expertise: deep understanding of laundry SME pain points"),
  bullet("AI engineering: production integration dengan Anthropic Claude (prompt engineering, RAG patterns, cost optimization)"),
  bullet("Indonesia market knowledge: regulasi UU PDP, SAK EMKM, ekosistem WA/QRIS"),

  h2("Team Expansion Plan (Post-Seed)"),
  dataTable(
    ["Role", "Priority", "Why"],
    [
      ["Sales Lead", "High", "Drive tenant acquisition + komunitas"],
      ["Customer Success", "High", "Onboarding + retention + support"],
      ["Engineer #2", "Medium", "Velocity + bus factor"],
      ["Content Marketer", "Medium", "SEO + edukasi pasar"],
      ["Designer (UI/UX)", "Low", "Already strong baseline"],
    ]
  ),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ THE ASK ═══════════
  h1("The Ask"),

  h2("Funding"),
  callout([
    new TextRun({ text: "Seeking: ", bold: true, color: INK, size: 26 }),
    new TextRun({ text: "Rp 1,5 – 2 Miliar (Seed Round)", bold: true, color: TEAL_DEEP, size: 28 }),
  ], "teal"),

  h2("Use of Funds"),
  dataTable(
    ["Kategori", "% Alokasi", "Tujuan"],
    [
      ["Sales & Marketing", "40%", "Hire sales + CS, marketing, komunitas, event"],
      ["Engineering", "30%", "Hire 1-2 engineers, AI advanced features"],
      ["Operations", "15%", "Customer support, training, content"],
      ["Infrastructure", "10%", "Production scaling (Hostinger → AWS/GCP), monitoring"],
      ["Working Capital", "5%", "Buffer untuk runway 18-24 bulan"],
    ]
  ),

  h2("Milestones (Post-Funding)"),
  numbered([new TextRun({ text: "Bulan 3: ", bold: true }), new TextRun("50 tenant aktif, MRR Rp 10jt+")]),
  numbered([new TextRun({ text: "Bulan 6: ", bold: true }), new TextRun("150 tenant, MRR Rp 30jt+, native app launch")]),
  numbered([new TextRun({ text: "Bulan 12: ", bold: true }), new TextRun("500 tenant, MRR Rp 100jt+, profitability dalam jangkauan")]),
  numbered([new TextRun({ text: "Bulan 18: ", bold: true }), new TextRun("1000+ tenant, MRR Rp 250jt+, ready for Series A")]),

  h2("Why Invest in LAMASY"),
  bullet([new TextRun({ text: "Live & functional product ", bold: true }), new TextRun("— bukan deck, bukan prototype. Risiko technical execution rendah.")]),
  bullet([new TextRun({ text: "Defensible moat via AI ", bold: true }), new TextRun("— kompetitor butuh 12-18 bulan replicate.")]),
  bullet([new TextRun({ text: "Market timing optimal ", bold: true }), new TextRun("— UMKM digitalisasi + AI affordable + regulasi push.")]),
  bullet([new TextRun({ text: "Scalable economics ", bold: true }), new TextRun("— SaaS dengan margin 80%+ at scale.")]),
  bullet([new TextRun({ text: "Founder commitment ", bold: true }), new TextRun("— full-time, technical depth, market obsession.")]),

  new Paragraph({ children: [new PageBreak()] }),

  // ═══════════ APPENDIX ═══════════
  h1("Appendix: Differentiator Summary"),

  h2("10 Reasons LAMASY Wins"),
  numbered([new TextRun({ text: "AI-First ERP ", bold: true }), new TextRun("— positioning unik 'ERP modern dengan integrasi AI pertama di Indonesia untuk laundry'.")]),
  numbered([new TextRun({ text: "Multi-tenant + Multi-outlet ", bold: true }), new TextRun("chain-friendly dengan HQ konsolidasi view.")]),
  numbered([new TextRun({ text: "Self-service customer ", bold: true }), new TextRun("via QR struk (track + portal + reward).")]),
  numbered([new TextRun({ text: "Antar-jemput integrated ", bold: true }), new TextRun("dengan kurir mobile app, signature + foto, no 3rd party needed.")]),
  numbered([new TextRun({ text: "SAK EMKM compliant ", bold: true }), new TextRun("laporan keuangan otomatis untuk pajak.")]),
  numbered([new TextRun({ text: "Smartlink migration ", bold: true }), new TextRun("AI-mapped Excel import dari kompetitor utama.")]),
  numbered([new TextRun({ text: "Sistem coin ", bold: true }), new TextRun("pay-as-you-use untuk premium features — fairness + transparency.")]),
  numbered([new TextRun({ text: "Comprehensive audit ", bold: true }), new TextRun("semua action sensitif logged → compliance-ready.")]),
  numbered([new TextRun({ text: "Modern UI design ", bold: true }), new TextRun("dual-theme professional (Ponytail outlet + AI Command Center SA).")]),
  numbered([new TextRun({ text: "PWA-ready ", bold: true }), new TextRun("installable ke device tanpa app store dependency.")]),

  new Paragraph({ spacing: { before: 800 }, alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Thank You", bold: true, size: 56, color: TEAL_DEEP })] }),
  new Paragraph({ alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Mari bicarakan lebih lanjut.", italics: true, size: 24, color: INK })] }),
  new Paragraph({ spacing: { before: 400 }, alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Ignatius Rizky · rizkyignatius@gmail.com", size: 22, color: ASH })] }),
  new Paragraph({ alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "lamasy.harpy.id", size: 22, color: ASH })] }),
];

// ─── Document Build ─────────────────────────────────────────────
const doc = new Document({
  creator: "LAMASY",
  title: "LAMASY — Investor Brief",
  description: "Investor pitch document — Seed Round 2026",
  styles: {
    default: { document: { run: { font: "Arial", size: 22 } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 44, bold: true, font: "Arial", color: NAVY },
        paragraph: { spacing: { before: 360, after: 240 }, outlineLevel: 0,
          border: { bottom: { style: BorderStyle.SINGLE, size: 12, color: TEAL, space: 6 } } } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 30, bold: true, font: "Arial", color: NAVY },
        paragraph: { spacing: { before: 280, after: 140 }, outlineLevel: 1 } },
      { id: "Heading3", name: "Heading 3", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 24, bold: true, font: "Arial", color: TEAL_DEEP },
        paragraph: { spacing: { before: 200, after: 80 }, outlineLevel: 2 } },
    ],
  },
  numbering: {
    config: [
      { reference: "bullets",
        levels: [
          { level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT,
            style: { paragraph: { indent: { left: 720, hanging: 360 } } } },
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
            new TextRun({ text: "LAMASY", bold: true, color: TEAL_DEEP, size: 20 }),
            new TextRun({ text: " · Investor Brief", color: ASH, size: 18 }),
            new TextRun({ text: "\tSeed Round 2026", color: ASH, size: 18, italics: true }),
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
            new TextRun({ text: "lamasy.harpy.id · Ignatius Rizky", color: ASH, size: 16 }),
            new TextRun({ text: "\tHalaman ", color: ASH, size: 16 }),
            new TextRun({ children: [PageNumber.CURRENT], color: ASH, size: 16 }),
            new TextRun({ text: " / ", color: ASH, size: 16 }),
            new TextRun({ children: [PageNumber.TOTAL_PAGES], color: ASH, size: 16 }),
          ],
        })],
      }),
    },
    children: [...coverPage, ...content],
  }],
});

Packer.toBuffer(doc).then(buffer => {
  fs.writeFileSync("/Users/rizky/Documents/lamasy/docs/exports/LAMASY-Investor-Brief.docx", buffer);
  console.log("✓ Investor brief created: LAMASY-Investor-Brief.docx");
});
