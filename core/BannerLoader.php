<?php
// ══════════════════════════════════════════════════════
// core/BannerLoader.php
//
// Ambil banner aktif utk dashboard. Filter by period + tier.
// ══════════════════════════════════════════════════════

class BannerLoader
{
    /**
     * Banner aktif untuk tenant ini.
     * @return array of banner rows
     */
    public static function activeForTenant(int $tenantId): array
    {
        try {
            $st = Database::get()->prepare(
                "SELECT * FROM saas_banners
                  WHERE is_active = 1
                    AND (starts_at IS NULL OR starts_at <= NOW())
                    AND (ends_at   IS NULL OR ends_at   >= NOW())
                  ORDER BY urutan ASC, id DESC
                  LIMIT 5"
            );
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Render carousel HTML (untuk embed di dashboard).
     */
    public static function renderCarousel(int $tenantId): string
    {
        $banners = self::activeForTenant($tenantId);
        if (empty($banners)) return '';

        $h = '<div class="banner-carousel" id="bannerCarousel">';
        foreach ($banners as $i => $b) {
            $color = htmlspecialchars($b['text_color']  ?? '#fff');
            // Image takes priority over gradient if uploaded
            if (!empty($b['image_url'])) {
                $bg = "url('" . htmlspecialchars($b['image_url']) . "') center/cover no-repeat";
                $hasImg = true;
            } else {
                $bg = htmlspecialchars($b['bg_gradient'] ?? 'linear-gradient(135deg,#0F7B6C,#10B981)');
                $hasImg = false;
            }
            $icon  = $b['icon']      ? '<span class="bn-icon">' . htmlspecialchars($b['icon']) . '</span>' : '';
            $cta   = ($b['cta_label'] && $b['cta_url'])
                ? '<a href="' . htmlspecialchars($b['cta_url']) . '" class="bn-cta">' . htmlspecialchars($b['cta_label']) . '</a>'
                : '';
            $desc  = $b['deskripsi']  ? '<div class="bn-desc">' . htmlspecialchars($b['deskripsi']) . '</div>' : '';
            // Image banners get a dark overlay for text legibility
            $overlay = $hasImg ? ' bn-has-img' : '';
            $h .= '<div class="bn-slide' . ($i === 0 ? ' active' : '') . $overlay . '" data-idx="' . $i . '" '
                . 'style="background:' . $bg . ';color:' . $color . ';">'
                . '<div class="bn-content">'
                . $icon
                . '<div class="bn-text"><div class="bn-title">' . htmlspecialchars($b['judul']) . '</div>'
                . $desc . '</div>'
                . $cta
                . '</div></div>';
        }
        // Pagination dots
        if (count($banners) > 1) {
            $h .= '<div class="bn-dots">';
            foreach ($banners as $i => $b) {
                $h .= '<button class="bn-dot ' . ($i === 0 ? 'active' : '') . '" data-idx="' . $i . '" onclick="bnGoto(' . $i . ')"></button>';
            }
            $h .= '</div>';
        }
        $h .= '</div>';

        // CSS & JS inline (sekali load)
        $h .= self::cssJs(count($banners));
        return $h;
    }

    private static function cssJs(int $count): string
    {
        return <<<HTML
<style>
.banner-carousel{position:relative;border-radius:18px;overflow:hidden;margin-bottom:22px;box-shadow:0 8px 32px rgba(15,123,108,.22),0 2px 8px rgba(0,0,0,.06);min-height:160px}
.bn-slide{display:none;padding:32px 36px 38px;min-height:160px;align-items:center;animation:bnFade .4s ease;position:relative;overflow:hidden}
.bn-slide.active{display:flex}
.bn-slide::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 85% 30%,rgba(255,255,255,.12),transparent 55%);pointer-events:none}
/* Image-mode banners get dark gradient overlay for text legibility */
.bn-slide.bn-has-img::after{background:linear-gradient(90deg,rgba(0,0,0,.55) 0%,rgba(0,0,0,.32) 55%,rgba(0,0,0,.18) 100%)}
.bn-content{display:flex;align-items:center;gap:24px;width:100%;flex-wrap:wrap;position:relative;z-index:1}
.bn-icon{font-size:54px;flex-shrink:0;filter:drop-shadow(0 2px 8px rgba(0,0,0,.15))}
.bn-text{flex:1;min-width:240px}
.bn-title{font-size:22px;font-weight:800;letter-spacing:-.005em;margin-bottom:6px;line-height:1.25;text-shadow:0 1px 2px rgba(0,0,0,.08)}
.bn-desc{font-size:14.5px;opacity:.95;line-height:1.55}
.bn-cta{display:inline-block;background:rgba(255,255,255,.22);color:inherit;text-decoration:none;padding:11px 22px;border-radius:100px;font-size:13.5px;font-weight:700;flex-shrink:0;border:1px solid rgba(255,255,255,.35);transition:all .2s ease;backdrop-filter:blur(4px)}
.bn-cta:hover{background:rgba(255,255,255,.32);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.12)}
.bn-dots{position:absolute;bottom:12px;left:50%;transform:translateX(-50%);display:flex;gap:7px;z-index:2}
.bn-dot{width:9px;height:9px;border-radius:50%;border:none;background:rgba(255,255,255,.45);cursor:pointer;padding:0;transition:all .2s ease}
.bn-dot.active{background:rgba(255,255,255,.98);width:28px;border-radius:5px}
@keyframes bnFade{from{opacity:0;transform:translateY(6px) scale(.99)}to{opacity:1;transform:translateY(0) scale(1)}}
@media (max-width:640px){.bn-slide{padding:22px 22px 30px;min-height:140px}.bn-title{font-size:17px}.bn-desc{font-size:13px}.bn-icon{font-size:38px}.bn-cta{font-size:12.5px;padding:8px 16px}.bn-content{gap:14px}}
</style>
<script>
(function(){
  const total = $count;
  if (total < 2) return;
  let cur = 0, timer;
  window.bnGoto = function(i){
    document.querySelectorAll('.bn-slide').forEach((el,idx)=>el.classList.toggle('active',idx===i));
    document.querySelectorAll('.bn-dot').forEach((el,idx)=>el.classList.toggle('active',idx===i));
    cur = i;
    clearInterval(timer);
    timer = setInterval(()=>bnGoto((cur+1)%total), 6000);
  };
  timer = setInterval(()=>bnGoto((cur+1)%total), 6000);
})();
</script>
HTML;
    }
}
