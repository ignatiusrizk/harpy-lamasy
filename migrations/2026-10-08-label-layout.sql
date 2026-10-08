-- Layout label stiker per outlet: classic (default) | modern. Juga dibuat lazy oleh outlet-settings.php & api/label.php.
ALTER TABLE outlets ADD COLUMN label_layout VARCHAR(10) NOT NULL DEFAULT 'classic';
