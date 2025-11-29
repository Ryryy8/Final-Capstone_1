-- ===================================================
-- Assessment Requests Table Update
-- Updates for new Assessor's Calendar functionality
-- Date: November 28, 2025
-- ===================================================

USE assesspro_db;

-- Ensure the table structure supports the new functionality
-- The existing schema already supports our changes, but let's verify and optimize

-- Check if the table exists and update if necessary
ALTER TABLE assessment_requests 
    MODIFY COLUMN inspection_category VARCHAR(100) NOT NULL COMMENT 'Building, Machinery, or Land Property',
    MODIFY COLUMN requested_inspection_date DATE DEFAULT NULL COMMENT 'System assigned date (clients view calendar only)',
    MODIFY COLUMN property_classification VARCHAR(50) DEFAULT NULL COMMENT 'Required only for Land Property category',
    MODIFY COLUMN purpose TEXT NOT NULL COMMENT 'Client purpose and preferred date mentioned in text';

-- Add index for better performance on inspection category queries
CREATE INDEX IF NOT EXISTS idx_category_date ON assessment_requests(inspection_category, requested_inspection_date);

-- Add index for better performance on location-based queries (for batch scheduling)
CREATE INDEX IF NOT EXISTS idx_location_category ON assessment_requests(location, inspection_category);

-- Update any existing "Property" records to "Land Property" (if any exist)
UPDATE assessment_requests 
SET inspection_category = 'Land Property' 
WHERE inspection_category = 'Property';

-- Verify table structure
DESCRIBE assessment_requests;

-- Show sample of current data structure
SELECT 
    COUNT(*) as total_requests,
    inspection_category,
    COUNT(CASE WHEN requested_inspection_date IS NOT NULL THEN 1 END) as requests_with_preferred_date,
    COUNT(CASE WHEN property_classification IS NOT NULL THEN 1 END) as requests_with_classification
FROM assessment_requests 
GROUP BY inspection_category;

-- ===================================================
-- Verification Queries
-- ===================================================

-- Check if all necessary columns exist with correct types
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'assesspro_db' 
    AND TABLE_NAME = 'assessment_requests'
    AND COLUMN_NAME IN (
        'inspection_category', 
        'requested_inspection_date', 
        'property_classification', 
        'purpose'
    )
ORDER BY ORDINAL_POSITION;

-- Success message
SELECT 'Assessment Requests table updated successfully for Assessor Calendar functionality' as status;