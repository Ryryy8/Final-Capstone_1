-- ===================================================
-- Rename purpose column to purpose_and_preferred_date
-- Update column name to reflect new functionality
-- Date: November 28, 2025
-- ===================================================

USE assesspro_db;

-- Check if the old column exists
SELECT 
    COUNT(*) as column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'assesspro_db' 
    AND TABLE_NAME = 'assessment_requests' 
    AND COLUMN_NAME = 'purpose';

-- Rename the column to reflect new functionality
ALTER TABLE assessment_requests 
CHANGE COLUMN purpose purpose_and_preferred_date TEXT NOT NULL 
COMMENT 'Client purpose and preferred date - clients view calendar and mention preferences in text';

-- Verify the column has been renamed
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'assesspro_db' 
    AND TABLE_NAME = 'assessment_requests'
    AND COLUMN_NAME = 'purpose_and_preferred_date';

-- Show updated table structure
DESCRIBE assessment_requests;

-- Success message
SELECT 'Column renamed successfully: purpose -> purpose_and_preferred_date' as status;