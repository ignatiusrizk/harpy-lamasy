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
            $bg    = htmlspecialchars($b['bg_gradient'] ?? 'linear-gradient(135deg,#0F7B6C,#10B981)');
            $color = htmlspecialchars($b['text_color']  ?? '#fff');
            $icon  = $b['icon']      ? '<span class="bn-icon">' . htmlspecialchars($b['icon']) . '</span>' : '';
            $cta   = ($b['cta_label'] && $b['cta_url'])
                ? '<a href="' . htmlspecialchars($b['cta_url']) . '" class="bn-cta">' . htmlspecialchars($b['cta_label']) . '</a>'
                : '';
            $desc  = $b['deskripsi']  ? '<div class="bn-desc">' . htmlspecialchars($b['deskripsi']) . '</div>' : '';
            $h .= '<div class="bn-slide ' . ($i === 0 ? 'active' : '') . '" data-idx="' . $i . '" '
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
.banner-carousel{position:relative;border-radius:14px;overflow:hidden;margin-bottom:18px;box-shadow:0 4px 16px rgba(15,123,108,.15);min-height:110px}
.bn-slide{display:none;padding:18px 24px;min-height:110px;align-items:center;animation:bnFade .35s ease}
.bn-slide.active{display:flex}
.bn-content{display:flex;align-items:center;gap:18px;width:100%;flex-wrap:wrap}
.bn-icon{font-size:36px;flex-shrink:0}
.bn-text{flex:1;min-width:200px}
.bn-title{font-size:17px;font-weight:800;letter-spacing:.01em;margin-bottom:4px;line-height:1.3}
.bn-desc{font-size:13px;opacity:.92;line-height:1.5}
.bn-cta{display:inline-block;background:rgba(255,255,255,.18);color:inherit;text-decoration:none;padding:8px 18px;border-radius:100px;font-size:13px;font-weight:700;flex-shrink:0;border:1px solid rgba(255,255,255,.3);transition:all .2s ease}
.bn-cta:hover{background:rgba(255,255,255,.28);transform:translateY(-1px)}
.bn-dots{position:absolute;bottom:8px;left:50%;transform:translateX(-50%);display:flex;gap:6px}
.bn-dot{width:8px;height:8px;border-radius:50%;border:none;background:rgba(255,255,255,.4);cursor:pointer;padding:0;transition:all .2s ease}
.bn-dot.active{background:rgba(255,255,255,.95);width:24px;border-radius:4px}
@keyframes bnFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
@media (max-width:640px){.bn-title{font-size:15px}.bn-icon{font-size:28px}.bn-cta{font-size:12px;padding:6px 14px}}
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
