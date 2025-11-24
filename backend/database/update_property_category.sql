-- ===================================================
-- Database Update Script
-- Change "Property" category to "Land Property"
-- ===================================================

USE assesspro_db;

-- Update existing assessment requests
UPDATE assessment_requests 
SET inspection_category = 'Land Property' 
WHERE inspection_category = 'Property';

-- Update existing scheduled inspections if any
UPDATE scheduled_inspections 
SET inspection_category = 'Land Property' 
WHERE inspection_category = 'Property';

-- Display updated records
SELECT 'Updated Assessment Requests:' as action;
SELECT id, name, inspection_category, created_at 
FROM assessment_requests 
WHERE inspection_category = 'Land Property'
ORDER BY created_at DESC;

SELECT 'Updated Scheduled Inspections:' as action;
SELECT id, inspection_category, inspection_date, created_at 
FROM scheduled_inspections 
WHERE inspection_category = 'Land Property'
ORDER BY created_at DESC;

SELECT 'Update completed successfully!' as status;