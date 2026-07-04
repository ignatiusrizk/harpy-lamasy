-- Analytics product tour (SA lihat % tenant selesai tour)
ALTER TABLE tenants ADD COLUMN tour_completed TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN tour_last_page VARCHAR(30) NULL DEFAULT NULL;
