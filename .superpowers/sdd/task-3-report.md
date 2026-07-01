# Task 3 Report: add-outlet — picker + simpan choice

## 1. Choice-save code + placement

Inserted after `$outletId = (int)$db->lastInsertId();` (original line 173), inside the `step2_submit` transaction block, before `$db->commit()`:

```php
// Simpan pilihan welcome kit (server-validasi key; else default)
if (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled()) {
    $choiceKey = WelcomeKit::resolveChoiceKey($_POST['welcome_kit_choice'] ?? null);
    if ($choiceKey !== null) {
        $db->prepare("UPDATE outlets SET welcome_kit_choice=? WHERE id=?")->execute([$choiceKey, $outletId]);
    }
}
```

This UPDATE runs inside the transaction so any failure rolls back with the INSERT.

## 2. Picker markup + where in form

The picker is placed inside `<form method="POST">` (the confirmation form containing `name="step2_submit"`), immediately after `<input type="hidden" name="_csrf">` and before `<div class="btn-row">`.

Variables `$wkOpts` and `$wkDefault` are computed at top of the form's PHP block.

- 0 options: nothing rendered
- Exactly 1 option: hidden input + plain text summary (no radio shown)
- 2+ options: radio labels with item preview, default key pre-checked

All user-facing strings pass through `htmlspecialchars()`.

## 3. "Yang kamu dapatkan" change

Old (called the removed `items()` method):
```php
<?php if (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled() && WelcomeKit::items()): ?>
<li><strong>🎁 Welcome kit fisik</strong> dikirim ke alamat outlet: <?= ... WelcomeKit::items() ... ?></li>
<?php endif; ?>
```

New (uses `options()`, defers detail to picker):
```php
<?php if (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled() && WelcomeKit::options()): ?>
<li><strong>🎁 Welcome kit fisik</strong> dikirim ke alamat outlet (pilih paket di bawah)</li>
<?php endif; ?>
```

## 4. php -l result

```
No syntax errors detected in add-outlet.php
```

## 5. grep items() result

```
grep -c "WelcomeKit::items(" add-outlet.php → 0
```

No remaining calls to the deprecated `items()` method.

## 6. Self-review

- Choice-save is inside the DB transaction. ✓
- `resolveChoiceKey` validates POST input server-side (invalid → default). ✓
- Picker is inside the confirmation `<form>` so `welcome_kit_choice` submits with `step2_submit`. ✓
- Single-option path uses hidden input (no unnecessary radio UI). ✓
- All output escaped with `htmlspecialchars`. ✓
- `WelcomeKit::defaultOption()` called once outside the per-option loop. ✓

## 7. Concerns

None. Implementation follows the brief exactly.
