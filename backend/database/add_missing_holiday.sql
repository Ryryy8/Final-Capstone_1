-- Add missing holiday to existing holidays table
-- This adds December 8 - Immaculate Conception which is missing from the database

USE assesspro_db;

-- Add December 8 - Immaculate Conception (this is the missing holiday)
INSERT IGNORE INTO holidays (name, date, is_recurring) VALUES
('Immaculate Conception', '2025-12-08', 1);

-- Verify it was added
SELECT * FROM holidays WHERE name = 'Immaculate Conception' OR date LIKE '%-12-08';

-- Show all December holidays
SELECT * FROM holidays WHERE date LIKE '%-12-%' ORDER BY date;