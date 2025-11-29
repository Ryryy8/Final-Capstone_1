-- ===================================================
-- Remove requested_inspection_date Column
-- Clean up assessment_requests table structure
-- Date: November 28, 2025
-- ===================================================

USE assesspro_db;

-- Check if the column exists before attempting to drop it
SELECT 
    COUNT(*) as column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'assesspro_db' 
    AND TABLE_NAME = 'assessment_requests' 
    AND COLUMN_NAME = 'requested_inspection_date';

-- Show current data in the column (for backup reference)
SELECT 
    COUNT(*) as total_requests,
    COUNT(CASE WHEN requested_inspection_date IS NOT NULL THEN 1 END) as requests_with_date,
    COUNT(CASE WHEN requested_inspection_date IS NULL THEN 1 END) as requests_without_date
FROM assessment_requests;

-- Drop indexes that reference the requested_inspection_date column
DROP INDEX IF EXISTS idx_category_date ON assessment_requests;

-- Remove the requested_inspection_date column
ALTER TABLE assessment_requests 
DROP COLUMN IF EXISTS requested_inspection_date;

-- Recreate performance index for category only (without date)
CREATE INDEX IF NOT EXISTS idx_inspection_category ON assessment_requests(inspection_category);

-- Verify the column has been removed
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'assesspro_db' 
    AND TABLE_NAME = 'assessment_requests'
ORDER BY ORDINAL_POSITION;

-- Show updated table structure
DESCRIBE assessment_requests;

-- Success message
SELECT 'requested_inspection_date column removed successfully - view-only calendar system active' as status;