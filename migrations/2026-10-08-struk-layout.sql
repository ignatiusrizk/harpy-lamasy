-- Layout nota thermal per outlet: classic (default) | modern. Opsional; juga dibuat lazy oleh StrukGenerator::saveTemplate.
ALTER TABLE hl_struk_template ADD COLUMN layout VARCHAR(10) NOT NULL DEFAULT 'classic';
